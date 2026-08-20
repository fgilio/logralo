<?php

declare(strict_types=1);

use App\Actions\AddComment;
use App\Concerns\InteractsWithMember;
use App\Exceptions\UserFacingException;
use App\Models\Comment;
use App\Models\Mark;
use App\Queries\MarkComments;
use Flux\Flux;
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
 * The draft is the browser's, which is why there is no `$body` here — not to
 * save requests, since a plain `wire:model` is deferred and cost none, but so
 * that the field can clear itself the moment the arrow is tapped rather than
 * when the round trip comes back. `send` takes the line as an argument and
 * answers whether it landed, which is what lets the box hand the words back
 * when it did not.
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

    /** The photo just opened, so the thread belongs to a different mark now. */
    #[On('photo-comments-open')]
    public function open(string $markId): void
    {
        $this->markId = $markId;

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

    /**
     * Whether the line landed. The field in front of this refuses an empty
     * comment and caps a long one, so a `false` from here is a thread this
     * member cannot write to or a body that was nothing but spaces — both of
     * which the Action is the authority on, and both of which end with the
     * words back in the box they came from.
     */
    public function send(string $body): bool
    {
        $mark = $this->mark;

        if (! $mark instanceof Mark) {
            return false;
        }

        try {
            resolve(AddComment::class)->handle($mark, $this->member(), $body);
        } catch (UserFacingException $userFacingException) {
            Flux::toast(text: $userFacingException->userMessage(), variant: 'warning');

            return false;
        }

        unset($this->comments);

        return true;
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
                {{-- `rise` on every line, and only the new one ever plays it:
                     the keys are what let Livewire keep the lines already on
                     screen, so the animation belongs to whichever node was
                     just inserted — the comment somebody sent while the photo
                     was open, arriving instead of appearing. --}}
                {{-- Read once: a date cast is rebuilt on every access, and the
                     line below asks for the same one twice. --}}
                @php($at = $comment->created_at)

                <div class="rise flex items-start gap-2.5" wire:key="comment-{{ $comment->id }}">
                    <x-avatar :user="$comment->user" size="sm" class="mt-px shrink-0" />

                    {{-- `wrap-anywhere` because a comment is whatever somebody
                         typed, and two hundred characters without a space in
                         them would otherwise push the thread off the side. --}}
                    <p class="min-w-0 flex-1 text-note leading-snug wrap-anywhere select-text">
                        <span class="font-semibold">{{ $comment->user->name }}</span>
                        <span class="text-white/85">{{ $comment->body }}</span>
                    </p>

                    {{-- In its own column rather than after the words, so a
                         timestamp never costs a comment a second line — the box
                         holds a handful of them and every line it spends is one
                         fewer thing said. Bare, unlike the feed's "hace 2h": a
                         column of times down the right of a thread is already
                         saying "ago", and the two words it saves are two words
                         of somebody's comment. --}}
                    <time
                        datetime="{{ $at?->toIso8601String() }}"
                        class="shrink-0 pt-px text-caption text-white/30 tabular-nums"
                    >{{ $at?->shortAbsoluteDiffForHumans() }}</time>
                </div>
            @empty
                <p class="py-2 text-center text-xs text-white/40">
                    Todavía nadie dijo nada.
                </p>
            @endforelse
        </div>

        @php($max = config()->integer('logralo.comments.max_length'))

        <form
            {{-- Keyed to the mark, so opening a different photo replaces the
                 form rather than morphing it: the draft is Alpine's, and a
                 half-typed line does not belong under somebody else's picture.
                 --}}
            wire:key="composer-{{ $this->markId }}"
            x-data="commentComposer({ max: {{ $max }} })"
            x-on:submit.prevent="send()"
            {{-- The keyboard-aware inset rather than the device's: while the
                 keys are up, the home indicator this would clear is behind
                 them. `resources/css/app.css` is where it goes to zero. --}}
            class="flex shrink-0 items-end gap-2 px-3 pt-1 pb-[max(0.75rem,var(--keyboard-safe-b))]"
        >
            <div class="relative min-w-0 flex-1">
                {{-- `flux:input` brings a light-mode kit into a dialog that is
                     always dark, so the field is plain markup with the viewer's
                     own colours.

                     A textarea rather than an input, and it grows: the cap is
                     280 characters and a one-line box shows twenty of them.
                     `field-sizing-content` is the whole of the growing, the
                     same way Flux sizes its own `rows="auto"` — an engine that
                     has never heard of it leaves the box at the one row the
                     input this replaced had, which is the floor, not a break.
                     Return still sends — the Action squishes a comment to one
                     line, so a newline was never going to survive the trip. --}}
                <textarea
                    x-model="draft"
                    x-on:keydown.enter.prevent="send()"
                    rows="1"
                    maxlength="{{ $max }}"
                    placeholder="Escribí algo…"
                    autocomplete="off"
                    autocapitalize="sentences"
                    enterkeyhint="send"
                    {{-- 16px, and not a pixel under. iOS zooms the page into
                         any field smaller than that the moment it is focused,
                         and never zooms back out: the viewer is left cropped
                         and off-centre with the photo half off the screen. --}}
                    class="block max-h-28 w-full resize-none overflow-y-auto rounded-3xl border-0 bg-white/10 py-2.5 pr-10 pl-4 text-base/5 field-sizing-content text-white placeholder:text-white/40 focus:ring-2 focus:ring-accent focus:outline-none"
                    aria-label="Comentar"
                    data-test="comment-body"
                ></textarea>

                {{-- Only once the cap is close enough to be the reason the
                     keyboard stopped answering. Before that it is a number
                     nobody asked for. --}}
                <span
                    x-show="left <= 40"
                    x-cloak
                    x-text="left"
                    class="pointer-events-none absolute right-3.5 bottom-2.5 text-caption tabular-nums"
                    :class="left <= 10 ? 'text-accent' : 'text-white/40'"
                    aria-hidden="true"
                ></span>
            </div>

            <button
                type="submit"
                {{-- The tap must not take the focus off the field: on a phone
                     that closes the keyboard, and the next comment starts with
                     opening it again. Cancelling the press is what keeps it. --}}
                x-on:mousedown.prevent
                x-bind:disabled="! ready"
                class="tap-target grid size-10 shrink-0 place-items-center rounded-full bg-accent text-accent-foreground transition active:scale-90 disabled:opacity-35 disabled:active:scale-100"
                aria-label="Enviar"
                data-test="comment-send"
            >
                <flux:icon name="arrow-up" variant="micro" x-show="! sending" />
                <flux:icon name="loading" variant="micro" class="animate-spin" x-show="sending" x-cloak />
            </button>
        </form>
    @endif
</div>
