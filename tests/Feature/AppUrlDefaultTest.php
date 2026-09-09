<?php

it('aligns the APP_URL default with artisan serve', function () {
    $example = file_get_contents(base_path('.env.example'));
    $appConfig = file_get_contents(base_path('config/app.php'));

    expect($example)->toContain('APP_URL=http://localhost:8000')
        ->and($appConfig)->toContain("env('APP_URL', 'http://localhost:8000')");
});
