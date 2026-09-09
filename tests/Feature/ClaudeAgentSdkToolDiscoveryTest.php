<?php

use App\Agents\Tools\SiteBuilder\SiteBuilderMessageTool;
use App\Agents\Tools\SiteBuilder\SiteBuilderProjectCreateTool;
use App\Agents\Tools\SiteBuilder\SiteBuilderStatusUpdateTool;
use App\Services\Agents\ClaudeAgentSdk;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

test('tool discovery includes tools from SiteBuilder subdirectory', function () {
    $sdk = app(ClaudeAgentSdk::class);

    $reflection = new ReflectionClass($sdk);
    $method = $reflection->getMethod('discoverTools');
    $method->setAccessible(true);
    $method->invoke($sdk);

    $property = $reflection->getProperty('toolRegistry');
    $property->setAccessible(true);
    $registry = $property->getValue($sdk);

    expect($registry)->toHaveKey('site_builder_message');
    expect($registry)->toHaveKey('site_builder_status_update');
    expect($registry)->toHaveKey('site_builder_project_create');

    expect($registry['site_builder_message'])->toBeInstanceOf(SiteBuilderMessageTool::class);
    expect($registry['site_builder_status_update'])->toBeInstanceOf(SiteBuilderStatusUpdateTool::class);
    expect($registry['site_builder_project_create'])->toBeInstanceOf(SiteBuilderProjectCreateTool::class);
});

test('tool discovery includes tools from root Tools directory', function () {
    $sdk = app(ClaudeAgentSdk::class);

    $reflection = new ReflectionClass($sdk);
    $method = $reflection->getMethod('discoverTools');
    $method->setAccessible(true);
    $method->invoke($sdk);

    $property = $reflection->getProperty('toolRegistry');
    $property->setAccessible(true);
    $registry = $property->getValue($sdk);

    expect($registry)->toHaveKey('web-search');
    expect($registry)->toHaveKey('create-task');
});

test('getToolInstance returns SiteBuilder tools', function () {
    $sdk = app(ClaudeAgentSdk::class);

    $reflection = new ReflectionClass($sdk);
    $method = $reflection->getMethod('getToolInstance');
    $method->setAccessible(true);

    $messageTool = $method->invoke($sdk, 'site_builder_message');
    $statusTool = $method->invoke($sdk, 'site_builder_status_update');

    expect($messageTool)->toBeInstanceOf(SiteBuilderMessageTool::class);
    expect($statusTool)->toBeInstanceOf(SiteBuilderStatusUpdateTool::class);
});
