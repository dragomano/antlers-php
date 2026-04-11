<?php

declare(strict_types=1);

it('renders if true branch', function (): void {
    expect(engine()->render('{{ if show }}yes{{ /if }}', ['show' => true]))->toBe('yes');
});

it('does not render if false', function (): void {
    expect(engine()->render('{{ if show }}yes{{ /if }}', ['show' => false]))->toBe('');
});

it('renders else branch', function (): void {
    $tpl = '{{ if logged_in }}Hi{{ else }}Login{{ /if }}';
    expect(engine()->render($tpl, ['logged_in' => false]))->toBe('Login');
});

it('renders elseif branch', function (): void {
    $tpl = '{{ if score > 90 }}A{{ elseif score > 70 }}B{{ else }}C{{ /if }}';
    expect(engine()->render($tpl, ['score' => 80]))->toBe('B');
});

it('renders first matching elseif', function (): void {
    $tpl = '{{ if x == 1 }}one{{ elseif x == 2 }}two{{ elseif x == 3 }}three{{ else }}other{{ /if }}';
    expect(engine()->render($tpl, ['x' => 3]))->toBe('three');
});

it('renders unless (inverted condition)', function (): void {
    $tpl = '{{ unless logged_in }}Please login{{ /unless }}';
    expect(engine()->render($tpl, ['logged_in' => false]))->toBe('Please login')
        ->and(engine()->render($tpl, ['logged_in' => true]))->toBe('');
});

it('handles logical and in condition', function (): void {
    $tpl = '{{ if active && subscribed }}VIP{{ /if }}';
    expect(engine()->render($tpl, ['active' => true, 'subscribed' => true]))->toBe('VIP')
        ->and(engine()->render($tpl, ['active' => true, 'subscribed' => false]))->toBe('');
});

it('handles logical or in condition', function (): void {
    $tpl = '{{ if admin || moderator }}Access{{ /if }}';
    expect(engine()->render($tpl, ['admin' => false, 'moderator' => true]))->toBe('Access');
});

it('handles comparison operators', function (): void {
    expect(engine()->render('{{ if count > 0 }}yes{{ /if }}', ['count' => 5]))->toBe('yes')
        ->and(engine()->render('{{ if count == 0 }}empty{{ /if }}', ['count' => 0]))->toBe('empty')
        ->and(engine()->render('{{ if count != 5 }}not five{{ /if }}', ['count' => 3]))->toBe('not five');
});

it('handles negation with !', function (): void {
    $tpl = '{{ if !active }}Inactive{{ /if }}';
    expect(engine()->render($tpl, ['active' => false]))->toBe('Inactive')
        ->and(engine()->render($tpl, ['active' => true]))->toBe('');
});

it('renders nested conditions', function (): void {
    $tpl = '{{ if outer }}{{ if inner }}both{{ else }}outer only{{ /if }}{{ /if }}';
    expect(engine()->render($tpl, ['outer' => true, 'inner' => false]))->toBe('outer only')
        ->and(engine()->render($tpl, ['outer' => true, 'inner' => true]))->toBe('both');
});
