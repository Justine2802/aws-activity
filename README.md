# Payroll API (PHP, Bref, AWS Lambda)

This project reads employee and payroll records from CSV files and exposes a JSON API through Bref and API Gateway. Payslips are rendered as PDFs and uploaded to S3.

## Requirements

- PHP 8.4 and Composer
- Node.js and the Serverless Framework CLI
- An AWS account, deployment credentials configured outside the project, and an existing S3 bucket
- The employee and payroll CSV files from the assigned dataset

`serverless.yml` is the deployment configuration used by Bref and Serverless Framework. It defines the PHP 8.4 Lambda handler, an API Gateway HTTP API route, the deployment region, and the narrowly scoped S3 upload permission. The Lambda runtime is specified on the function using Bref's `php-84` runtime.

## Install and prepare the data

Install the project dependencies:

```powershell
composer install
```

Place the dataset files at `data/employees.csv` and `data/payroll.csv`. The first row of each file must contain its column names. The API keeps the employee CSV's actual column names in employee records and recognizes these normalized columns (column names are case-insensitive; spaces and punctuation are treated as underscores):

- Employees: `employee_id` (or `staff_id`/`id`), and `full_name`/`employee_name`/`name`, or `first_name` plus `last_name`. Other columns such as department and contact details are returned as supplied.
- Payroll: `payroll_id` (or `id`), `employee_id` (or `staff_id`), gross pay (`gross_pay`, `gross_salary`, or `gross_amount`), and net pay (`net_pay`, `net_salary`, or `net_amount`). Deductions are read from `deductions`, `total_deductions`, or `deduction_amount`; if absent they are calculated as gross pay minus net pay. Pay-period information is read from `pay_period_start` and `pay_period_end` (shown as a date range), or from `pay_period`, `period`, `pay_date`, or `payment_date`.

Set `EMPLOYEES_CSV` or `PAYROLL_CSV` to use a different file path. Paths can be absolute or relative to the application's working directory. Missing files, invalid rows, and unrecognized required columns produce explicit error responses; sample records are not embedded in the application.

## API

| Method | Path | Result |
| --- | --- | --- |
| `GET` | `/api/employees` | Employee CSV records |
| `GET` | `/api/reports/payroll` | Record count and gross, deduction, and net totals |
| `GET` | `/api/payslips/{payroll_id}` | Employee details and payroll figures |
| `POST` | `/api/payslips/{payroll_id}/generate` | Generate PDF and upload it as `payslips/{payroll_id}.pdf` |

The PDF includes employee details, pay period, gross pay, deductions, and net pay. The generate endpoint returns the S3 object key; it does not make the bucket public.

## Run locally

```powershell
php -S 127.0.0.1:8000 -t public
```

Then call the endpoints at `http://127.0.0.1:8000`. PDF generation additionally requires AWS credentials available through the standard AWS SDK credential chain, `PAYSLIPS_BUCKET`, and permission to put objects in the bucket.

Generate and upload a payslip using a `POST` request (opening the URL in a browser sends `GET` and will not generate the PDF):

```powershell
Invoke-RestMethod -Method Post -Uri "http://127.0.0.1:8000/api/payslips/1/generate"
```

Replace `1` with an existing payroll ID. The response contains an S3 object key such as `payslips/1.pdf`. Set `PAYSLIPS_BUCKET` and configure AWS credentials before calling this endpoint.

## Deploy

Install Serverless Framework if needed, configure AWS credentials using a supported AWS profile/credential provider (never put credentials in this repository), and set environment values before deploying:

```powershell
$env:AWS_REGION = "us-east-1"
$env:PAYSLIPS_BUCKET = "payroll-esguerra"
npx serverless deploy
```

The screenshot-provided bucket (`payroll-esguerra`) and region (`us-east-1`) are the defaults in `serverless.yml`; the environment variables above can override them. The configuration grants the Lambda role only `s3:PutObject` on `payslips/*` within the configured bucket. Confirm that the role has been deployed with this policy, then call the returned API URL and verify the resulting PDF under the `payslips/` prefix in the S3 console.

Deployment and upload have not been performed from this project. They require the account owner to configure valid AWS credentials, create the bucket, and supply the dataset.
