<?php

use App\Support\QueryHelpers;

describe('escapeLikeWildcards', function () {
    it('escapes percent sign wildcard', function () {
        expect(QueryHelpers::escapeLikeWildcards('100%'))->toBe('100\%');
        expect(QueryHelpers::escapeLikeWildcards('%test%'))->toBe('\%test\%');
    });

    it('escapes underscore wildcard', function () {
        expect(QueryHelpers::escapeLikeWildcards('test_value'))->toBe('test\_value');
        expect(QueryHelpers::escapeLikeWildcards('_prefix'))->toBe('\_prefix');
    });

    it('escapes multiple wildcards', function () {
        expect(QueryHelpers::escapeLikeWildcards('%_%'))->toBe('\%\_\%');
        expect(QueryHelpers::escapeLikeWildcards('a%b_c%d_e'))->toBe('a\%b\_c\%d\_e');
    });

    it('returns unchanged string without wildcards', function () {
        expect(QueryHelpers::escapeLikeWildcards('normal text'))->toBe('normal text');
        expect(QueryHelpers::escapeLikeWildcards('hello world'))->toBe('hello world');
        expect(QueryHelpers::escapeLikeWildcards(''))->toBe('');
    });

    it('handles special characters that are not wildcards', function () {
        expect(QueryHelpers::escapeLikeWildcards("it's a test"))->toBe("it's a test");
        expect(QueryHelpers::escapeLikeWildcards('test@example.com'))->toBe('test@example.com');
        expect(QueryHelpers::escapeLikeWildcards('price: $100'))->toBe('price: $100');
    });
});

describe('likePattern', function () {
    it('creates both-sided wildcard pattern by default', function () {
        expect(QueryHelpers::likePattern('test'))->toBe('%test%');
    });

    it('creates start wildcard pattern', function () {
        expect(QueryHelpers::likePattern('test', 'start'))->toBe('test%');
    });

    it('creates end wildcard pattern', function () {
        expect(QueryHelpers::likePattern('test', 'end'))->toBe('%test');
    });

    it('creates exact pattern without wildcards', function () {
        expect(QueryHelpers::likePattern('test', 'exact'))->toBe('test');
    });

    it('escapes user input in all pattern types', function () {
        expect(QueryHelpers::likePattern('100%', 'both'))->toBe('%100\%%');
        expect(QueryHelpers::likePattern('test_val', 'start'))->toBe('test\_val%');
        expect(QueryHelpers::likePattern('%admin', 'end'))->toBe('%\%admin');
        expect(QueryHelpers::likePattern('_root_', 'exact'))->toBe('\_root\_');
    });
});

describe('SQL injection prevention', function () {
    it('prevents wildcard injection in search queries', function () {
        // Malicious input attempting to match all records
        $maliciousInput = '100%';
        $escaped = QueryHelpers::escapeLikeWildcards($maliciousInput);

        // The % should be escaped with a backslash
        expect($escaped)->toBe('100\%');

        // Verify the escape sequence is present
        expect(str_contains($escaped, '\%'))->toBeTrue();
    });

    it('prevents underscore single-char matching', function () {
        // Underscore matches any single character in SQL LIKE
        $maliciousInput = 'adm_n';  // Would match 'admin', 'admon', etc.
        $escaped = QueryHelpers::escapeLikeWildcards($maliciousInput);

        expect($escaped)->toBe('adm\_n');
    });
});
