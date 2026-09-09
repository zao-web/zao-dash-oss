<?php

return [
    'financial_archive_root' => env('FINANCIAL_ARCHIVE_ROOT', env('HOME')
        ? rtrim((string) env('HOME'), '/').'/Desktop/Financials'
        : null),
];
