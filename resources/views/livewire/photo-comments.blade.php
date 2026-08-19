<?php

declare(strict_types=1);

use App\Actions\AddComment;
use App\Concerns\InteractsWithMember;
use App\Models\Comment;
use App\Models\Mark;
use App\Queries\MarkComments;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The thread under the open photo.
 *
 * One component for the whole feed, next to the one viewer it fills, for the
 * same reason the viewer itself is shared: twenty of these idling behind
 * twenty cards is twenty components to hydrate on every reaction tap. It
 * learns which photo it belongs to when one opens.
 *
 * Everything else in the viewer is drawn by Alpine off the card's payload.
 * Comments cannot be: they change after the card was rendered, and a card that
 * carried its thread would be re-serialised on every render of a feed that
 * re-renders whole.
 *
 * Nothing here reaches the feed. A wall of pictures is what the feed is for,
 * and a comment is something you go and look at.
 */
new class extends Component
{
    use InteractsWithMember;

    /**
     * Locked because an unlocked public property is a client-writable input:
     * this one names a row whose thread gets read and written. Locked stops the
     * browser from setting it, and `visibleTo` below is what stops a mark id
     * lifted from somewhere else — the two are not the same guard.
     */
    #[Locked]
    public ?string $markId = null;

    public string $body = '';

    /** The photo just opened, so the thread belongs to a different mark now. */
    #[On('photo-comments-open')]
    public function open(string $markId): void
    {
        $this->markId = $markId;
        $this->reset('body');
        $this->resetValidation();

        unset($this->mark, $this->comments);
    }

    #[Computed]
    public function mark(): ?Mark
    {
        if ($this->markId === null) {
            return null;
        }

        // Scoped to what this member is allowed to see, so a mark id from
        // anywhere else cannot open a private goal's thread.
        return Mark::query()
            ->visibleTo($this->member())
            ->find($this->markId);
    }

    /** @return Collection<int, Comment> */
    #[Computed]
    public function comments(): Collection
    {
        $mark = $this->mark;

        return $mark instanceof Mark
            ? resolve(MarkComments::class)->for($mark)
            : collect();
    }

    public function send(): void
    {
        $mark = $this->mark;

        if (! $mark instanceof Mark) {
            return;
        }

        $validated = $this->validate([
            'body' => ['required', 'string', 'max:'.config('logralo.comments.max_length')],
        ]);

        resolve(AddComment::class)->handle($mark, $this->member(), $validated['body']);

        $this->reset('body');

        unset($this->comments);
    }
};

?>

<div class="flex min-h-38 shrink-0 basis-[36%] flex-col border-t border-white/10">
    @if ($this->markId === null)
        {{-- Nothing has been opened yet, so there is nothing to draw and no
             query to run. --}}
    @else
        {{-- Drawn bottom-up: `flex-col-reverse` over a newest-first list puts
             the end of the conversation against the field and starts the box
             scrolled there, with no script to run after the render. As many
             comments as the box fits are visible; the rest are one thumb away.
             --}}
        <div
            class="flex min-h-0 flex-1 flex-col-reverse gap-2.5 overflow-y-auto overscroll-contain px-4 py-3"
            data-test="viewer-comments"
        >
            @forelse ($this->comments as $comment)
                <div class="flex items-start gap-2.5" wire:key="comment-{{ $comment->id }}">
                    <x-avatar :user="$comment->user" size="sm" class="mt-px shrink-0" />

                    <p class="min-w-0 text-note leading-snug select-text">
                        <span class="font-semibold">{{ $comment->user->name }}</span>
                        <span class="text-white/85">{{ $comment->body }}</span>
                    </p>
                </div>
            @empty
                <p class="py-2 text-center text-xs text-white/40">
                    Todavía nadie dijo nada.
                </p>
            @endforelse
        </div>

        <form
            wire:submit="send"
            class="flex shrink-0 items-center gap-2 px-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]"
        >
            {{-- `flux:input` brings a light-mode kit into a dialog that is
                 always dark, so the field is plain markup with the viewer's own
                 colours. --}}
            <input
                type="text"
                wire:model="body"
                maxlength="{{ config('logralo.comments.max_length') }}"
                placeholder="Escribí algo…"
                autocomplete="off"
                required
                class="min-w-0 flex-1 rounded-full border-0 bg-white/10 px-4 py-2.5 text-sm text-white placeholder:text-white/40 focus:ring-2 focus:ring-accent focus:outline-none"
                aria-label="Comentar"
                data-test="comment-body"
            >

            <button
                type="submit"
                class="tap-target grid size-10 shrink-0 place-items-center rounded-full bg-accent text-accent-foreground transition active:scale-90"
                aria-label="Enviar"
                data-test="comment-send"
            >
                <flux:icon name="arrow-up" variant="micro" wire:loading.remove wire:target="send" />
                <flux:icon name="loading" variant="micro" class="animate-spin" wire:loading wire:target="send" />
            </button>
        </form>
    @endif
</div>
