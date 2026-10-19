<?php declare(strict_types=1);

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Dompdf\Dompdf;
use Dompdf\Options;

final class PayrollApi
{
    private string $dataDirectory;

    public function __construct(?string $dataDirectory = null)
    {
        $this->dataDirectory = $dataDirectory ?? dirname(__DIR__) . '/data';
    }

    public function dispatch(string $method, string $path): array
    {
        try {
            if (preg_match('#^/api/payslips/([^/]+)/generate$#', $path, $matches) === 1) {
                if ($method !== 'POST') {
                    return $this->error('This endpoint requires the POST method.', 405);
                }

                $payrollId = rawurldecode($matches[1]);
                $payslip = $this->payslip($payrollId);
                $objectKey = $this->uploadPayslip($payslip);

                return [
                    'statusCode' => 200,
                    'body' => [
                        'success' => true,
                        'message' => 'Payslip generated and uploaded.',
                        'data' => [
                            'payroll_id' => $payslip['payroll_id'],
                            'payslip_file' => $objectKey,
                        ],
                    ],
                ];
            }

            if ($method === 'GET' && $path === '/api/employees') {
                return $this->success($this->readCsv('EMPLOYEES_CSV', 'employees.csv'));
            }

            if ($method === 'GET' && $path === '/api/reports/payroll') {
                return $this->success($this->payrollReport());
            }

            if ($method === 'GET' && preg_match('#^/api/payslips/([^/]+)$#', $path, $matches) === 1) {
                return $this->success($this->payslip(rawurldecode($matches[1])));
            }

            return $this->error('Not found.', 404);
        } catch (ApiException $exception) {
            if ($exception->getCode() >= 500) {
                error_log($exception->getMessage());
            }
            return $this->error($exception->getMessage(), $exception->getCode());
        } catch (AwsException $exception) {
            error_log('Payslip S3 upload failed: ' . $exception->getMessage());
            return $this->error('Payslip upload failed.', 502);
        } catch (RuntimeException $exception) {
            error_log($exception->getMessage());
            return $this->error('The request could not be completed.', 500);
        }
    }

    private function payrollReport(): array
    {
        $payrollRecords = $this->readCsv('PAYROLL_CSV', 'payroll.csv');
        $totals = [
            'total_payroll_records' => count($payrollRecords),
            'total_gross_pay' => 0.0,
            'total_deductions' => 0.0,
            'total_net_pay' => 0.0,
        ];

        foreach ($payrollRecords as $record) {
            $gross = $this->money($record, ['gross_pay', 'gross_salary', 'gross_amount']);
            $net = $this->money($record, ['net_pay', 'net_salary', 'net_amount']);
            $deductions = $this->optionalMoney($record, ['deductions', 'total_deductions', 'deduction_amount'])
                ?? ($gross - $net);

            $totals['total_gross_pay'] += $gross;
            $totals['total_deductions'] += $deductions;
            $totals['total_net_pay'] += $net;
        }

        foreach (['total_gross_pay', 'total_deductions', 'total_net_pay'] as $field) {
            $totals[$field] = round($totals[$field], 2);
        }

        return $totals;
    }

    private function payslip(string $payrollId): array
    {
        $payrollRecords = $this->readCsv('PAYROLL_CSV', 'payroll.csv');
        $payroll = null;
        foreach ($payrollRecords as $record) {
            if ((string) $this->value($record, ['payroll_id', 'id']) === $payrollId) {
                $payroll = $record;
                break;
            }
        }

        if ($payroll === null) {
            throw new ApiException('Payslip not found.', 404);
        }

        $employeeId = (string) $this->value($payroll, ['employee_id', 'staff_id']);
        $employee = null;
        foreach ($this->readCsv('EMPLOYEES_CSV', 'employees.csv') as $record) {
            if ((string) $this->value($record, ['employee_id', 'staff_id', 'id']) === $employeeId) {
                $employee = $record;
                break;
            }
        }

        if ($employee === null) {
            throw new ApiException('Employee for this payslip was not found.', 500);
        }

        $gross = $this->money($payroll, ['gross_pay', 'gross_salary', 'gross_amount']);
        $net = $this->money($payroll, ['net_pay', 'net_salary', 'net_amount']);
        $deductions = $this->optionalMoney($payroll, ['deductions', 'total_deductions', 'deduction_amount'])
            ?? ($gross - $net);

        return [
            'payroll_id' => $this->value($payroll, ['payroll_id', 'id']),
            'employee_name' => $this->employeeName($employee),
            'employee_details' => $employee,
            'pay_period' => $this->payPeriod($payroll),
            'gross_pay' => round($gross, 2),
            'deductions' => round($deductions, 2),
            'net_pay' => round($net, 2),
        ];
    }

    private function payPeriod(array $payroll): ?string
    {
        $start = $this->optionalValue($payroll, ['pay_period_start', 'period_start']);
        $end = $this->optionalValue($payroll, ['pay_period_end', 'period_end']);
        if ($start !== null || $end !== null) {
            return trim(implode(' - ', array_filter(
                [(string) ($start ?? ''), (string) ($end ?? '')],
                fn ($value) => $value !== ''
            )));
        }

        $period = $this->optionalValue($payroll, ['pay_period', 'period', 'pay_date', 'payment_date']);
        return $period === null ? null : (string) $period;
    }

    private function uploadPayslip(array $payslip): string
    {
        $bucket = getenv('PAYSLIPS_BUCKET');
        if ($bucket === false || trim($bucket) === '') {
            throw new RuntimeException('PAYSLIPS_BUCKET must be set to the destination S3 bucket.');
        }

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->payslipHtml($payslip), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        $payrollId = (string) $payslip['payroll_id'];
        $objectKey = 'payslips/' . rawurlencode($payrollId) . '.pdf';
        $client = new S3Client([
            'version' => 'latest',
            'region' => getenv('AWS_REGION') ?: 'us-east-1',
        ]);
        $client->putObject([
            'Bucket' => $bucket,
            'Key' => $objectKey,
            'Body' => $dompdf->output(),
            'ContentType' => 'application/pdf',
        ]);

        return $objectKey;
    }

    private function payslipHtml(array $payslip): string
    {
        $employeeRows = '';
        foreach ($payslip['employee_details'] as $field => $value) {
            $employeeRows .= '<tr><th>' . $this->escape((string) $field) . '</th><td>'
                . $this->escape((string) $value) . '</td></tr>';
        }

        $payPeriod = $payslip['pay_period'] === null ? 'Not provided' : (string) $payslip['pay_period'];

        return '<!doctype html><html><head><meta charset="UTF-8"><style>'
            . 'body{font-family:DejaVu Sans,sans-serif;color:#222}h1{color:#183153}'
            . 'table{width:100%;border-collapse:collapse;margin:16px 0}'
            . 'th,td{border:1px solid #bbb;padding:8px;text-align:left}'
            . '.amount{text-align:right}th{background:#f1f4f8}'
            . '</style></head><body><h1>Employee Payslip</h1>'
            . '<p><strong>Payroll ID:</strong> ' . $this->escape((string) $payslip['payroll_id']) . '</p>'
            . '<p><strong>Employee:</strong> ' . $this->escape($payslip['employee_name']) . '</p>'
            . '<p><strong>Pay period:</strong> ' . $this->escape($payPeriod) . '</p>'
            . '<h2>Employee details</h2><table>' . $employeeRows . '</table>'
            . '<h2>Payroll figures</h2><table>'
            . '<tr><th>Gross pay</th><td class="amount">' . number_format($payslip['gross_pay'], 2) . '</td></tr>'
            . '<tr><th>Deductions</th><td class="amount">' . number_format($payslip['deductions'], 2) . '</td></tr>'
            . '<tr><th>Net pay</th><td class="amount">' . number_format($payslip['net_pay'], 2) . '</td></tr>'
            . '</table></body></html>';
    }

    private function readCsv(string $environmentVariable, string $defaultFile): array
    {
        $configuredPath = getenv($environmentVariable);
        $path = $configuredPath !== false && $configuredPath !== ''
            ? $configuredPath
            : $this->dataDirectory . '/' . $defaultFile;
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open CSV file "%s".', $path));
        }

        try {
            $headers = fgetcsv($handle, null, ',', '"', '');
            if ($headers === false || $headers === [null]) {
                throw new RuntimeException(sprintf('CSV file "%s" is empty or has no header row.', $path));
            }

            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
            $normalizedHeaders = array_map(fn ($header) => $this->normalizeHeader((string) $header), $headers);
            if (in_array('', $normalizedHeaders, true) || count(array_unique($normalizedHeaders)) !== count($normalizedHeaders)) {
                throw new RuntimeException(sprintf('CSV file "%s" has empty or duplicate column names.', $path));
            }

            $rows = [];
            while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
                if ($values === [null]) {
                    continue;
                }
                if (count($values) !== count($headers)) {
                    throw new RuntimeException(sprintf('CSV file "%s" contains a row with an unexpected number of columns.', $path));
                }

                $row = [];
                foreach ($headers as $index => $header) {
                    $row[(string) $header] = $this->convertCsvValue(
                        $normalizedHeaders[$index],
                        trim((string) $values[$index])
                    );
                }
                $rows[] = $row;
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    private function convertCsvValue(string $header, string $value): int|float|string
    {
        if (in_array($header, ['employee_id', 'payroll_id', 'department_id', 'id', 'staff_id'], true)
            && preg_match('/^(0|[1-9]\d*)$/', $value) === 1) {
            return (int) $value;
        }
        if ($value !== '' && is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }
        return $value;
    }

    private function normalizeHeader(string $header): string
    {
        $header = strtolower(trim($header));
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $header), '_');
    }

    private function money(array $record, array $aliases): float
    {
        $value = $this->value($record, $aliases);
        $numericValue = str_replace([',', '$', ' '], '', (string) $value);
        if (!is_numeric($numericValue)) {
            throw new RuntimeException(sprintf('Payroll amount "%s" is not numeric.', (string) $value));
        }
        return (float) $numericValue;
    }

    private function optionalMoney(array $record, array $aliases): ?float
    {
        $value = $this->optionalValue($record, $aliases);
        if ($value === null || $value === '') {
            return null;
        }
        $numericValue = str_replace([',', '$', ' '], '', (string) $value);
        if (!is_numeric($numericValue)) {
            throw new RuntimeException(sprintf('Payroll amount "%s" is not numeric.', (string) $value));
        }
        return (float) $numericValue;
    }

    private function value(array $record, array $aliases): int|float|string
    {
        return $this->optionalValue($record, $aliases)
            ?? throw new RuntimeException('A required CSV column is missing: ' . implode(', ', $aliases) . '.');
    }

    private function optionalValue(array $record, array $aliases): int|float|string|null
    {
        foreach ($record as $header => $value) {
            if (in_array($this->normalizeHeader((string) $header), $aliases, true)) {
                return $value;
            }
        }
        return null;
    }

    private function employeeName(array $employee): string
    {
        $name = $this->optionalValue($employee, ['full_name', 'employee_name', 'name']);
        if ($name !== null && trim((string) $name) !== '') {
            return (string) $name;
        }

        $parts = array_filter([
            $this->optionalValue($employee, ['first_name', 'firstname']),
            $this->optionalValue($employee, ['middle_name', 'middlename']),
            $this->optionalValue($employee, ['last_name', 'lastname']),
        ], fn ($part) => $part !== null && trim((string) $part) !== '');

        if ($parts === []) {
            throw new RuntimeException('Employee CSV must contain a name column or first/last name columns.');
        }

        return implode(' ', array_map('strval', $parts));
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function success(array $data): array
    {
        return ['statusCode' => 200, 'body' => ['success' => true, 'data' => $data]];
    }

    private function error(string $message, int $statusCode): array
    {
        return ['statusCode' => $statusCode, 'body' => ['success' => false, 'message' => $message]];
    }
}

final class ApiException extends RuntimeException
{
}
