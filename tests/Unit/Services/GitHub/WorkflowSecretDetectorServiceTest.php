<?php

use App\Models\GitHubRepo;
use App\Models\GitHubWorkflowSecretRequirement;
use App\Services\GitHub\WorkflowSecretDetectorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->repo = GitHubRepo::factory()->create([
        'full_name' => 'zao-web/sierra-theme',
    ]);

    $this->detector = app(WorkflowSecretDetectorService::class);
});

test('parses simple workflow and extracts secret references', function () {
    $workflowYaml = <<<'YAML'
name: Deploy
on: push
jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - name: Deploy to FTP
        env:
          FTP_HOST: ${{ secrets.FTP_HOST }}
          FTP_USER: ${{ secrets.FTP_USER }}
          FTP_PASSWORD: ${{ secrets.FTP_PASSWORD }}
        run: echo "Deploying..."
YAML;

    $secrets = $this->detector->extractSecretsFromYaml($workflowYaml);

    expect($secrets)->toContain('FTP_HOST', 'FTP_USER', 'FTP_PASSWORD')
        ->and($secrets)->toHaveCount(3);
});

test('extracts secrets from workflow_call inputs', function () {
    $workflowYaml = <<<'YAML'
name: Reusable Deploy
on:
  workflow_call:
    secrets:
      API_KEY:
        required: true
      OPTIONAL_TOKEN:
        required: false
jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - run: echo "Deploy"
YAML;

    $result = $this->detector->extractSecretsFromYaml($workflowYaml, parseWorkflowCall: true);

    expect($result['required'])->toContain('API_KEY')
        ->and($result['optional'])->toContain('OPTIONAL_TOKEN');
});

test('extracts secrets from multiple jobs', function () {
    $workflowYaml = <<<'YAML'
name: CI/CD
on: push
jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - name: Build
        env:
          NPM_TOKEN: ${{ secrets.NPM_TOKEN }}
        run: npm ci
  deploy:
    runs-on: ubuntu-latest
    steps:
      - name: Deploy
        env:
          AWS_KEY: ${{ secrets.AWS_ACCESS_KEY }}
          AWS_SECRET: ${{ secrets.AWS_SECRET_KEY }}
        run: aws deploy
YAML;

    $secrets = $this->detector->extractSecretsFromYaml($workflowYaml);

    expect($secrets)->toContain('NPM_TOKEN', 'AWS_ACCESS_KEY', 'AWS_SECRET_KEY')
        ->and($secrets)->toHaveCount(3);
});

test('extracts secrets from with clause in action steps', function () {
    $workflowYaml = <<<'YAML'
name: Deploy
on: push
jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: some-action/deploy@v1
        with:
          api-key: ${{ secrets.DEPLOY_API_KEY }}
          token: ${{ secrets.GITHUB_TOKEN }}
YAML;

    $secrets = $this->detector->extractSecretsFromYaml($workflowYaml);

    expect($secrets)->toContain('DEPLOY_API_KEY', 'GITHUB_TOKEN');
});

test('extracts secrets from run commands', function () {
    $workflowYaml = <<<'YAML'
name: Deploy
on: push
jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - run: |
          curl -X POST -H "Authorization: ${{ secrets.API_TOKEN }}" https://api.example.com
          echo "${{ secrets.WEBHOOK_SECRET }}" | base64
YAML;

    $secrets = $this->detector->extractSecretsFromYaml($workflowYaml);

    expect($secrets)->toContain('API_TOKEN', 'WEBHOOK_SECRET');
});

test('detects job environment and associates secrets', function () {
    $workflowYaml = <<<'YAML'
name: Deploy
on: push
jobs:
  deploy-staging:
    runs-on: ubuntu-latest
    environment: staging
    steps:
      - env:
          API_KEY: ${{ secrets.STAGING_API_KEY }}
        run: deploy
  deploy-prod:
    runs-on: ubuntu-latest
    environment: production
    steps:
      - env:
          API_KEY: ${{ secrets.PROD_API_KEY }}
        run: deploy
YAML;

    $result = $this->detector->extractSecretsWithEnvironments($workflowYaml);

    expect($result['staging'])->toContain('STAGING_API_KEY')
        ->and($result['production'])->toContain('PROD_API_KEY');
});

test('ignores GITHUB_TOKEN as it is always available', function () {
    $workflowYaml = <<<'YAML'
name: PR Check
on: pull_request
jobs:
  check:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with:
          token: ${{ secrets.GITHUB_TOKEN }}
      - env:
          MY_SECRET: ${{ secrets.MY_SECRET }}
        run: echo "Check"
YAML;

    $secrets = $this->detector->extractSecretsFromYaml($workflowYaml, excludeBuiltIn: true);

    expect($secrets)->not->toContain('GITHUB_TOKEN')
        ->and($secrets)->toContain('MY_SECRET');
});

test('fetches and parses all workflows from repo', function () {
    Http::fake([
        'api.github.com/repos/zao-web/sierra-theme/contents/.github/workflows' => Http::response([
            ['name' => 'deploy.yml', 'type' => 'file', 'path' => '.github/workflows/deploy.yml'],
            ['name' => 'test.yml', 'type' => 'file', 'path' => '.github/workflows/test.yml'],
        ], 200),
        'api.github.com/repos/zao-web/sierra-theme/contents/.github/workflows/deploy.yml' => Http::response([
            'content' => base64_encode("name: Deploy\non: push\njobs:\n  deploy:\n    runs-on: ubuntu-latest\n    steps:\n      - env:\n          KEY: \${{ secrets.DEPLOY_KEY }}\n        run: deploy"),
        ], 200),
        'api.github.com/repos/zao-web/sierra-theme/contents/.github/workflows/test.yml' => Http::response([
            'content' => base64_encode("name: Test\non: push\njobs:\n  test:\n    runs-on: ubuntu-latest\n    steps:\n      - env:\n          KEY: \${{ secrets.TEST_KEY }}\n        run: test"),
        ], 200),
    ]);

    $result = $this->detector->detectAllRequirements($this->repo);

    expect($result)->toHaveKey('.github/workflows/deploy.yml')
        ->and($result)->toHaveKey('.github/workflows/test.yml')
        ->and($result['.github/workflows/deploy.yml'])->toContain('DEPLOY_KEY')
        ->and($result['.github/workflows/test.yml'])->toContain('TEST_KEY');
});

test('stores detected requirements in database', function () {
    Http::fake([
        'api.github.com/repos/zao-web/sierra-theme/contents/.github/workflows' => Http::response([
            ['name' => 'deploy.yml', 'type' => 'file', 'path' => '.github/workflows/deploy.yml'],
        ], 200),
        'api.github.com/repos/zao-web/sierra-theme/contents/.github/workflows/deploy.yml' => Http::response([
            'content' => base64_encode("name: Deploy\non: push\njobs:\n  deploy:\n    runs-on: ubuntu-latest\n    steps:\n      - env:\n          KEY: \${{ secrets.DEPLOY_KEY }}\n        run: deploy"),
        ], 200),
    ]);

    $this->detector->detectAndStore($this->repo);

    $this->assertDatabaseHas('github_workflow_secret_requirements', [
        'github_repo_id' => $this->repo->id,
        'workflow_path' => '.github/workflows/deploy.yml',
        'secret_name' => 'DEPLOY_KEY',
        'source' => 'parsed',
    ]);
});

test('updates existing requirements on re-scan', function () {
    GitHubWorkflowSecretRequirement::factory()->create([
        'github_repo_id' => $this->repo->id,
        'workflow_path' => '.github/workflows/deploy.yml',
        'secret_name' => 'OLD_SECRET',
        'source' => 'parsed',
    ]);

    Http::fake([
        'api.github.com/repos/zao-web/sierra-theme/contents/.github/workflows' => Http::response([
            ['name' => 'deploy.yml', 'type' => 'file', 'path' => '.github/workflows/deploy.yml'],
        ], 200),
        'api.github.com/repos/zao-web/sierra-theme/contents/.github/workflows/deploy.yml' => Http::response([
            'content' => base64_encode("name: Deploy\non: push\njobs:\n  deploy:\n    runs-on: ubuntu-latest\n    steps:\n      - env:\n          KEY: \${{ secrets.NEW_SECRET }}\n        run: deploy"),
        ], 200),
    ]);

    $this->detector->detectAndStore($this->repo, clearOldParsed: true);

    $this->assertDatabaseMissing('github_workflow_secret_requirements', [
        'github_repo_id' => $this->repo->id,
        'secret_name' => 'OLD_SECRET',
    ]);

    $this->assertDatabaseHas('github_workflow_secret_requirements', [
        'github_repo_id' => $this->repo->id,
        'secret_name' => 'NEW_SECRET',
    ]);
});

test('preserves manual requirements on re-scan', function () {
    GitHubWorkflowSecretRequirement::factory()->create([
        'github_repo_id' => $this->repo->id,
        'workflow_path' => '.github/workflows/deploy.yml',
        'secret_name' => 'MANUAL_SECRET',
        'source' => 'manual',
    ]);

    Http::fake([
        'api.github.com/repos/zao-web/sierra-theme/contents/.github/workflows' => Http::response([
            ['name' => 'deploy.yml', 'type' => 'file', 'path' => '.github/workflows/deploy.yml'],
        ], 200),
        'api.github.com/repos/zao-web/sierra-theme/contents/.github/workflows/deploy.yml' => Http::response([
            'content' => base64_encode("name: Deploy\non: push\njobs:\n  deploy:\n    runs-on: ubuntu-latest\n    steps:\n      - run: deploy"),
        ], 200),
    ]);

    $this->detector->detectAndStore($this->repo, clearOldParsed: true);

    $this->assertDatabaseHas('github_workflow_secret_requirements', [
        'github_repo_id' => $this->repo->id,
        'secret_name' => 'MANUAL_SECRET',
        'source' => 'manual',
    ]);
});

test('handles repo with no workflows directory', function () {
    Http::fake([
        'api.github.com/repos/zao-web/sierra-theme/contents/.github/workflows' => Http::response([
            'message' => 'Not Found',
        ], 404),
    ]);

    $result = $this->detector->detectAllRequirements($this->repo);

    expect($result)->toBeEmpty();
});

test('extracts secrets from matrix strategy', function () {
    $workflowYaml = <<<'YAML'
name: Matrix Deploy
on: push
jobs:
  deploy:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        env: [staging, production]
    steps:
      - env:
          API_KEY: ${{ secrets[format('{0}_API_KEY', matrix.env)] }}
        run: deploy
YAML;

    $secrets = $this->detector->extractSecretsFromYaml($workflowYaml);

    expect($secrets)->toBeEmpty();
});

test('handles malformed yaml gracefully', function () {
    $malformedYaml = "name: Bad\non: push\njobs: [this is not valid yaml for jobs";

    $secrets = $this->detector->extractSecretsFromYaml($malformedYaml);

    expect($secrets)->toBeArray();
});

test('get all unique required secrets for repo', function () {
    GitHubWorkflowSecretRequirement::factory()->create([
        'github_repo_id' => $this->repo->id,
        'workflow_path' => '.github/workflows/deploy.yml',
        'secret_name' => 'FTP_PASSWORD',
        'is_required' => true,
    ]);

    GitHubWorkflowSecretRequirement::factory()->create([
        'github_repo_id' => $this->repo->id,
        'workflow_path' => '.github/workflows/test.yml',
        'secret_name' => 'API_KEY',
        'is_required' => true,
    ]);

    GitHubWorkflowSecretRequirement::factory()->create([
        'github_repo_id' => $this->repo->id,
        'workflow_path' => '.github/workflows/build.yml',
        'secret_name' => 'FTP_PASSWORD',
        'is_required' => true,
    ]);

    $secrets = $this->detector->getAllRequiredSecrets($this->repo);

    expect($secrets)->toContain('FTP_PASSWORD', 'API_KEY')
        ->and($secrets)->toHaveCount(2);
});
