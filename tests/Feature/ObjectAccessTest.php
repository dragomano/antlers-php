<?php

declare(strict_types=1);

use Bugo\Antlers\Exceptions\AntlersRuntimeException;
use Bugo\Antlers\GuardPolicy;

/**
 * Names never collide on purpose: a private $ssn next to a public ssn() would
 * make "did the private value leak?" and "did the method get called?" the same
 * question.
 */
function objectUser(): object
{
    return new class {
        public string $name = 'Alice';

        private string $ssn = '123-45-6789';

        protected string $internal = 'hidden';

        public function role(): string
        {
            return 'admin';
        }

        public function revealSsn(): string
        {
            return $this->ssn;
        }

        public function boom(): string
        {
            throw new LogicException('domain failure');
        }

        private function secret(): string
        {
            return 'classified';
        }
    };
}

it('reads public object properties without an opt-in', function (): void {
    expect(engine()->render('{{ user.name }}', ['user' => objectUser()]))->toBe('Alice');
});

it('does not call object methods by default', function (): void {
    expect(engine()->render('{{ user.role }}', ['user' => objectUser()]))->toBe('');
});

it('calls object methods once the opt-in is enabled', function (): void {
    $engine = engine()->setAllowObjectMethodCalls(true);

    expect($engine->render('{{ user.role }}', ['user' => objectUser()]))->toBe('admin');
});

it('never calls a private or protected method, even with the opt-in on', function (): void {
    // Symmetric with private properties: a non-public method is not a reachable
    // member, so it stays a miss instead of reaching the call and throwing.
    expect(engine()->setAllowObjectMethodCalls(true)->render('{{ user.secret }}', ['user' => objectUser()]))
        ->toBe('');
});

it('never exposes a private or protected property, with or without the opt-in', function (): void {
    foreach ([false, true] as $allow) {
        $render = static fn(string $template): string
            => engine()->setAllowObjectMethodCalls($allow)->render($template, ['user' => objectUser()]);

        expect($render('{{ user.ssn }}'))->toBe('')
            ->and($render('{{ user.internal }}'))->toBe('')
            ->and($render('{{ if user.ssn }}leaked{{ else }}blocked{{ /if }}'))->toBe('blocked')
            ->and($render('{{ user.ssn | upper }}'))->toBe('')
            ->and($render('{{ user | pluck:"ssn" | join:"," }}'))->toBe('')
            ->and($render('{{ user | sort:"ssn" }}'))->toBe('');
    }

    // Same value, two paths: the method is opt-in, the property never is.
    expect(engine()->setAllowObjectMethodCalls(true)->render('{{ user.revealSsn }}', ['user' => objectUser()]))
        ->toBe('123-45-6789')
        ->and(engine()->render('{{ user.revealSsn }}', ['user' => objectUser()]))->toBe('');
});

it('wraps a Throwable from an object member into the runtime policy', function (): void {
    // The method only runs with the opt-in on — the case where a foreign
    // exception could otherwise escape render().
    $lenient = engine()->setAllowObjectMethodCalls(true);

    expect($lenient->render('{{ user.boom }}', ['user' => objectUser()]))->toBe('');

    $strict = engine()->setAllowObjectMethodCalls(true)->setStrictMode(true);

    expect(fn(): string => $strict->render('{{ user.boom }}', ['user' => objectUser()]))
        ->toThrow(AntlersRuntimeException::class, 'domain failure');
});

it('keeps GuardPolicy in charge of redaction', function (): void {
    $engine = engine()
        ->setAllowObjectMethodCalls(true)
        ->setGuardPolicy(new GuardPolicy(variables: ['user.name']));

    expect($engine->render('{{ user.name }}', ['user' => objectUser()]))->toBe('')
        ->and($engine->render('{{ user }}', ['user' => objectUser()]))->not->toContain('Alice');
});
