<?php

return [
    'wsdl' => env('TIM_WSDL'),
    'username' => env('TIM_USER_NAME'),
    'password_sha1' => env('TIM_PASS'),
    'storage_disk' => env('TIM_STORAGE_DISK', 's3'),
    'storage_bucket' => env('AWS_BUCKET', env('S3_BUCKET_NAME')),
    'document_hosts' => array_values(array_filter(array_map(
        static fn (string $host): string => strtolower(trim($host)),
        explode(',', (string) env('TIM_DOCUMENT_HOSTS', ''))
    ))),
    'timeout_seconds' => (int) env('TIM_TIMEOUT_SECONDS', 30),
    'connect_timeout_seconds' => (int) env('TIM_CONNECT_TIMEOUT_SECONDS', 10),
    'max_soap_bytes' => (int) env('TIM_MAX_SOAP_BYTES', 10 * 1024 * 1024),
    'max_document_bytes' => (int) env('TIM_MAX_DOCUMENT_BYTES', 25 * 1024 * 1024),
    'max_documents' => (int) env('TIM_MAX_DOCUMENTS', 100),
    'report_upload_max_kb' => (int) env('TIM_REPORT_UPLOAD_MAX_KB', 25 * 1024),
    'signed_url_minutes' => (int) env('TIM_SIGNED_URL_MINUTES', 15),
];
