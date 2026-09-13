<?php

declare(strict_types=1);

use Bugo\Antlers\Engine;
use Bugo\Antlers\Exceptions\AntlersRuntimeException;
use Bugo\Antlers\Tags\AbstractTag;
use Bugo\Antlers\Tags\TagContext;

it('keeps nested invocations of the same class tag isolated', function (): void {
    $engine = new Engine();
    $engine->addTag('nest', new class extends AbstractTag {
        public function index(TagContext $context): string
        {
            return '[' . $context->content() . '|id=' . $context->param('id') . ']';
        }
    });

    expect($engine->render('{{ nest id="outer" }}{{ nest id="inner" }}x{{ /nest }}{{ /nest }}'))
        ->toBe('[[x|id=inner]|id=outer]');
});

it('dispatches only public tag methods with a safe argument count', function (): void {
    $engine = new Engine();
    $engine->addTag('probe', new class extends AbstractTag {
        public function index(TagContext $context): string
        {
            return 'index:' . $context->param('value', 'none');
        }

        public function show(TagContext $context): string
        {
            return 'show:' . $context->param('value', 'none');
        }

        private function hidden(): string
        {
            return 'hidden';
        }

        public function invalid(string $one, string $two): string
        {
            return $one . $two;
        }
    });

    expect($engine->render('{{ probe:show value="ok" }}'))->toBe('show:ok')
        ->and($engine->render('{{ probe:hidden }}'))->toBe('')
        ->and($engine->render('{{ probe:invalid }}'))->toBe('')
        ->and(fn(): string => $engine->setStrictMode(true)->render('{{ probe:hidden }}'))
        ->toThrow(AntlersRuntimeException::class, 'Unknown method "hidden" on tag "probe".')
        ->and(fn(): string => $engine->render('{{ probe:invalid }}'))
        ->toThrow(AntlersRuntimeException::class, 'must accept at most one TagContext');
});
