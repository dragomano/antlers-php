<?php

declare(strict_types=1);

use Bugo\Antlers\Parser\BraceScanner;

describe('BraceScanner', function (): void {
    it('finds a simple closing brace', function (): void {
        expect(BraceScanner::closingBrace('{a}', 0))->toBe(2);
    });

    it('tracks nested braces by depth', function (): void {
        expect(BraceScanner::closingBrace('{{a}}}', 0))->toBe(4);
    });

    it('ignores braces inside double quotes', function (): void {
        expect(BraceScanner::closingBrace('{a="} {"}', 0))->toBe(8);
    });

    it('ignores braces inside single quotes', function (): void {
        expect(BraceScanner::closingBrace("{a='} {'}", 0))->toBe(8);
    });

    it('skips escaped characters inside quotes', function (): void {
        expect(BraceScanner::closingBrace('{a="\\"}"}', 0))->toBe(8);
    });

    it('skips escaped braces outside quotes', function (): void {
        expect(BraceScanner::closingBrace('{a@{b@}c}', 0))->toBe(8);
    });

    it('returns null for an unclosed brace', function (): void {
        expect(BraceScanner::closingBrace('{a', 0))->toBeNull();
    });

    it('returns null when the rest is swallowed by an open quote', function (): void {
        expect(BraceScanner::closingBrace('{a="}', 0))->toBeNull();
    });
});
