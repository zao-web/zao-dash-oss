<?php

use App\Models\AuditLog;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('is a valid model', function () {
    $log = new AuditLog;

    expect($log)->toBeInstanceOf(\Illuminate\Database\Eloquent\Model::class);
});

test('has audit_logs table', function () {
    expect((new AuditLog)->getTable())->toBe('audit_logs');
});

test('can be instantiated', function () {
    $log = new AuditLog;

    expect($log)->toBeInstanceOf(AuditLog::class);
});
