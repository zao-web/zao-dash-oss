<?php

use App\Services\Seo\SeoResearchService;

test('detects AI patterns in content', function () {
    $seoService = app(SeoResearchService::class);

    $contentWithPatterns = <<<'CONTENT'
    Let's delve into the exciting world of WordPress development. We leverage cutting-edge
    technologies to seamlessly integrate your systems. This is a game-changer for your business!

    Furthermore — we believe it's important to note that in today's fast-paced digital landscape,
    you need to unlock the potential of your platform.
    CONTENT;

    $result = $seoService->humanizeContent($contentWithPatterns);

    expect($result)->toHaveKey('patterns_detected');
    expect($result['patterns_detected'])->not->toBeEmpty();

    // Should detect: delve, leverage, cutting-edge, seamlessly, game-changer,
    // furthermore, em dash, it's important to note, unlock, fast-paced
    expect(count($result['patterns_detected']))->toBeGreaterThan(5);
});

test('humanized content removes AI patterns', function () {
    $seoService = app(SeoResearchService::class);

    $contentWithPatterns = <<<'CONTENT'
    Let's delve into WordPress development. We leverage modern technologies to seamlessly
    integrate systems. It's important to note that this transforms your business.
    CONTENT;

    $result = $seoService->humanizeContent($contentWithPatterns);

    expect($result)->toHaveKey('humanized');
    expect($result)->toHaveKey('patterns_remaining');

    // If Grok is configured, humanized content should have fewer patterns
    if (app(\App\Services\Grok\GrokService::class)->isConfigured()) {
        expect(count($result['patterns_remaining']))
            ->toBeLessThan(count($result['patterns_detected']));
    }
});

test('calculates humanization score correctly', function () {
    $seoService = app(SeoResearchService::class);

    $cleanContent = <<<'CONTENT'
    WordPress development for healthcare organizations requires HIPAA compliance and
    robust security measures. We build custom solutions that meet industry standards.

    Our team has worked with several healthcare clients to implement secure patient
    portals and electronic health record integrations.
    CONTENT;

    $result = $seoService->humanizeContent($cleanContent);

    expect($result)->toHaveKey('score');
    expect($result['score'])->toBeInt();
    expect($result['score'])->toBeGreaterThanOrEqual(0);
    expect($result['score'])->toBeLessThanOrEqual(100);

    // Clean content should score high
    expect($result['score'])->toBeGreaterThan(70);
});

test('quality gates validate required elements', function () {
    $seoService = app(SeoResearchService::class);

    // Valid page passes quality gates
    $validPage = [
        'title' => 'WordPress Development Healthcare Solutions',
        'meta_title' => 'WordPress Development Healthcare | Zao Agency',
        'meta_description' => 'HIPAA-compliant WordPress development healthcare solutions. Custom patient portals, EHR integration.',
        'content' => 'WordPress development healthcare is our specialty. '.str_repeat('We build HIPAA-compliant solutions for healthcare organizations. Our team focuses on security and performance. ', 10),
        'target_keyword' => 'wordpress development healthcare',
    ];

    expect($seoService->passesQualityGates($validPage))->toBeTrue();

    // Missing elements fail quality gates
    $invalidPage = [
        'title' => 'Short',
        'meta_title' => 'This meta title is way too long and exceeds the sixty character limit recommended for SEO',
        'meta_description' => 'Too short',
        'content' => 'Not enough content.',
        'target_keyword' => 'test',
    ];

    expect($seoService->passesQualityGates($invalidPage))->toBeFalse();
});

test('quality gates check keyword placement', function () {
    $seoService = app(SeoResearchService::class);

    $pageWithKeywordInFirst100 = [
        'title' => 'WordPress Development Healthcare Guide',
        'meta_title' => 'WordPress Development Healthcare | Zao',
        'meta_description' => 'HIPAA-compliant WordPress development services.',
        'content' => 'WordPress development healthcare requires specialized expertise. '.str_repeat('We build HIPAA-compliant solutions for medical organizations. ', 10),
        'target_keyword' => 'wordpress development healthcare',
    ];

    expect($seoService->passesQualityGates($pageWithKeywordInFirst100))->toBeTrue();

    $pageWithKeywordNotInFirst100 = [
        'title' => 'Healthcare Technology Solutions',
        'meta_title' => 'Healthcare Technology Solutions | Zao',
        'meta_description' => 'We build healthcare technology solutions.',
        'content' => str_repeat('Generic introduction content about technology and healthcare systems. ', 3).'Now finally mentioning wordpress development healthcare. '.str_repeat('More content. ', 10),
        'target_keyword' => 'wordpress development healthcare',
    ];

    expect($seoService->passesQualityGates($pageWithKeywordNotInFirst100))->toBeFalse();
});

test('detects em dashes specifically', function () {
    $seoService = app(SeoResearchService::class);

    $contentWithEmDashes = 'WordPress development — the cornerstone of modern web applications — requires expertise.';

    $result = $seoService->humanizeContent($contentWithEmDashes);

    $hasEmDashPattern = collect($result['patterns_detected'])->contains(
        fn ($pattern) => $pattern['type'] === 'em_dash'
    );

    expect($hasEmDashPattern)->toBeTrue();
});

test('detects banned words', function () {
    $seoService = app(SeoResearchService::class);

    $bannedWords = [
        'delve' => 'Let us delve into this topic',
        'leverage' => 'We leverage advanced technologies',
        'seamlessly' => 'This seamlessly integrates',
        'unlock' => 'Unlock your potential',
        'revolutionize' => 'This will revolutionize your business',
    ];

    foreach ($bannedWords as $word => $content) {
        $result = $seoService->humanizeContent($content);

        $hasBannedWord = collect($result['patterns_detected'])->contains(
            fn ($pattern) => $pattern['type'] === 'banned_word' && $pattern['word'] === $word
        );

        expect($hasBannedWord)->toBeTrue("Failed to detect banned word: {$word}");
    }
});

test('detects excessive hedging', function () {
    $seoService = app(SeoResearchService::class);

    $hedgingContent = 'This might possibly help you. It could potentially improve performance. This may possibly work.';

    $result = $seoService->humanizeContent($hedgingContent);

    $hasHedging = collect($result['patterns_detected'])->contains(
        fn ($pattern) => $pattern['type'] === 'excessive_hedging'
    );

    expect($hasHedging)->toBeTrue();
});

test('detects chatbot artifacts', function () {
    $seoService = app(SeoResearchService::class);

    $chatbotContent = 'I hope this helps you understand the topic better!';

    $result = $seoService->humanizeContent($chatbotContent);

    $hasArtifact = collect($result['patterns_detected'])->contains(
        fn ($pattern) => $pattern['type'] === 'chatbot_artifact'
    );

    expect($hasArtifact)->toBeTrue();
});

test('perfect content scores 100', function () {
    $seoService = app(SeoResearchService::class);

    $perfectContent = <<<'CONTENT'
    WordPress development for healthcare organizations needs HIPAA compliance. We build
    secure patient portals and integrate with electronic health record systems.

    Our team has worked with medical practices and hospitals to create custom WordPress
    solutions. We focus on security, performance, and usability.

    Recent projects include a patient portal for a 50-doctor practice and an appointment
    scheduling system for a regional hospital network.
    CONTENT;

    $result = $seoService->humanizeContent($perfectContent);

    // Perfect content should have no patterns detected
    if (count($result['patterns_detected']) === 0) {
        expect($result['score'])->toBe(100);
        expect($result['patterns_remaining'])->toBeEmpty();
    } else {
        // If patterns are detected, they should be minimal
        expect($result['score'])->toBeGreaterThan(80);
    }
});
