<?php

declare(strict_types=1);

use App\Actions\SendFeedback;
use App\Concerns\InteractsWithMember;
use Flux\Flux;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "¿Qué pasó?" — the one way anything wrong with the app leaves the phone it
 * happened on.
 *
 * It rides in the layout rather than on a screen, because the moment somebody
 * has something to report is the moment they are already somewhere: mid-feed,
 * mid-form, staring at whatever just misbehaved. Making them navigate to a
 * settings page to say so is how a bug becomes a shrug.
 *
 * The screen they were on travels with the message. The layout passes it in —
 * it is the one that knows which page it is drawing — and it is locked here,
 * because it is context we collect rather than a field the browser fills in.
 */
new class extends Component
{
    use InteractsWithMember;

    public string $body = '';

    #[Locked]
    public ?string $page = null;

    public function send(): void
    {
        $validated = $this->validate([
            'body' => ['required', 'string', 'max:'.config('logralo.feedback.max_length')],
        ]);

        resolve(SendFeedback::class)->handle($this->member(), $validated['body'], $this->page);

        $this->reset('body');

        Flux::modal('feedback')->close();

        // No inbox is promised and no ticket number exists, so the toast says
        // the true thing: it arrived, and a person reads it.
        Flux::toast(text: 'Gracias. Lo leemos.', variant: 'success');
    }
};

?>

<div>
    {{-- Floating over the bottom-right corner, clear of the safe area on the
         phones with a home indicator. Small and quiet: it is there every day
         for something that happens a handful of times. --}}
    <button
        type="button"
        x-on:click="$flux.modal('feedback').show()"
        class="tap-target fixed right-4 bottom-[calc(1rem+var(--spacing-safe-b))] z-30 grid size-12 place-items-center rounded-full bg-zinc-900 text-white shadow-lg ring-1 ring-black/10 transition active:scale-95 dark:bg-white dark:text-zinc-900 dark:ring-white/10"
        aria-label="Contar algo de la app"
        data-test="open-feedback"
    >
        <flux:icon name="chat-bubble-oval-left" class="size-5" />
    </button>

    <x-sheet name="feedback">
        <flux:heading size="lg" class="font-display tracking-wide">¿Qué pasó?</flux:heading>

        <form wire:submit="send" class="mt-4 flex flex-col gap-4">
            <flux:textarea
                wire:model="body"
                placeholder="¿Qué se rompió, o qué podría estar mejor?"
                rows="4"
                maxlength="{{ config('logralo.feedback.max_length') }}"
                aria-label="Mensaje"
                data-test="feedback-body"
            />

            <flux:error name="body" />

            {{-- Speaking instead of typing. Absent where the browser has no
                 speech recognition, which is why it hides itself rather than
                 being rendered by something the server knows: the server sees
                 a user agent, and whether this works is a question about the
                 engine behind it. See `dictation` in `resources/js/app.js`. --}}
            <div
                x-data="dictation({ max: {{ config('logralo.feedback.max_length') }} })"
                x-show="supported"
                x-cloak
                class="-mt-2 flex"
            >
                <flux:button
                    type="button"
                    variant="subtle"
                    size="sm"
                    icon="microphone"
                    x-on:click="toggle()"
                    x-bind:aria-pressed="listening ? 'true' : 'false'"
                    x-bind:class="listening && 'text-red-600! dark:text-red-400!'"
                    data-test="dictate"
                >
                    <span x-text="listening ? 'Escuchando…' : 'Hablar'">Hablar</span>
                </flux:button>
            </div>

            <flux:button
                type="submit"
                variant="primary"
                class="w-full"
                data-test="send-feedback"
            >
                Enviar
            </flux:button>
        </form>
    </x-sheet>
</div>
