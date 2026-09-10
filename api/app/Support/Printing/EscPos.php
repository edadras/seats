<?php

namespace App\Support\Printing;

/**
 * A receipt as the bytes a thermal printer understands.
 *
 * ESC/POS is not a document format; it is a stream of instructions to a print head that moves in
 * one direction and never comes back. So this builds forwards — set a style, print a line, move on
 * — and every method returns `$this` because that is the only order in which any of it makes sense.
 *
 * **What this is for.** A box office window with a roll printer wants a ticket in its hand a second
 * after the card clears, not a PDF somebody opens, chooses a printer for, and waits for. Raw bytes
 * are how that happens: a local print agent pushes them straight at the device.
 *
 * **What it cannot do, said plainly.** Text mode prints from the printer's own code page, so a
 * printer bought in Berlin will not have Persian in it, and no instruction here can put it there.
 * A ticket whose event name is not Latin has to be printed from the browser's print view instead,
 * which goes through the operating system's driver and can draw any script at all. This class
 * refuses to guess: characters the chosen code page cannot carry are dropped rather than turned
 * into a row of question marks that looks like a printer fault.
 */
class EscPos
{
    private string $out = '';

    /** Roll widths, in characters, at the usual font A. The two sizes anybody actually buys. */
    public const COLUMNS = [58 => 32, 80 => 48];

    public function __construct(private readonly int $columns = 48) {}

    public static function forWidth(int $millimetres): self
    {
        return new self(self::COLUMNS[$millimetres] ?? self::COLUMNS[80]);
    }

    public function columns(): int
    {
        return $this->columns;
    }

    /** Wake the printer and clear whatever the last job left set. */
    public function start(): self
    {
        // ESC @ — initialise. Then ESC t 16: code page 1252, the widest Latin one printers agree on.
        $this->out .= "\x1B\x40\x1B\x74\x10";

        return $this;
    }

    public function align(string $where): self
    {
        $this->out .= "\x1B\x61".chr(['left' => 0, 'centre' => 1, 'right' => 2][$where] ?? 0);

        return $this;
    }

    public function bold(bool $on = true): self
    {
        $this->out .= "\x1B\x45".chr($on ? 1 : 0);

        return $this;
    }

    /** Double height, double width, or both. A ticket has one line worth shouting: the seat. */
    public function size(int $width = 1, int $height = 1): self
    {
        $n = ((max(1, min(8, $width)) - 1) << 4) | (max(1, min(8, $height)) - 1);
        $this->out .= "\x1D\x21".chr($n);

        return $this;
    }

    public function line(string $text = ''): self
    {
        $this->out .= $this->encode($text)."\n";

        return $this;
    }

    /**
     * A label on the left and a value on the right, on one line.
     *
     * The commonest shape on any receipt and the easiest to get wrong: padded to the roll's own
     * width, and if the two will not fit the value wins, because "Seat" matters less than "F12".
     */
    public function columnsPair(string $left, string $right): self
    {
        $left = $this->encode($left);
        $right = $this->encode($right);
        $room = $this->columns - mb_strlen($right, '8bit');

        if ($room < 1) {
            return $this->line($right);
        }

        $this->out .= str_pad(mb_substr($left, 0, $room - 1, '8bit'), $room, ' ').$right."\n";

        return $this;
    }

    public function rule(string $character = '-'): self
    {
        return $this->line(str_repeat($character, $this->columns));
    }

    /**
     * The code, printed as a QR the door can scan.
     *
     * Four instructions rather than one: choose the model, set how big a module is, set how much
     * damage it may survive, store the data, print it. Level M and six-dot modules is what fits a
     * ticket token across 58mm and still scans off a crumpled roll.
     */
    public function qr(string $payload, int $module = 6): self
    {
        $store = "\x31\x50\x30".$payload;
        $length = strlen($store);

        $this->out .= "\x1D\x28\x6B\x04\x00\x31\x41\x32\x00"; // model 2
        $this->out .= "\x1D\x28\x6B\x03\x00\x31\x43".chr(max(1, min(16, $module)));
        $this->out .= "\x1D\x28\x6B\x03\x00\x31\x45\x31"; // error correction M
        $this->out .= "\x1D\x28\x6B".chr($length % 256).chr(intdiv($length, 256)).$store;
        $this->out .= "\x1D\x28\x6B\x03\x00\x31\x51\x30"; // print it

        return $this;
    }

    public function feed(int $lines = 1): self
    {
        $this->out .= "\x1B\x64".chr(max(0, min(255, $lines)));

        return $this;
    }

    /** Partial cut, leaving a tab so the ticket does not fall on the floor. */
    public function cut(): self
    {
        return $this->feed(3)->raw("\x1D\x56\x01");
    }

    /** The cash drawer, which is wired to the printer and opened by pulsing it. */
    public function kickDrawer(): self
    {
        return $this->raw("\x1B\x70\x00\x19\xFA");
    }

    public function raw(string $bytes): self
    {
        $this->out .= $bytes;

        return $this;
    }

    public function bytes(): string
    {
        return $this->out;
    }

    /**
     * Into the printer's code page, dropping what it cannot carry.
     *
     * Silence rather than substitution on purpose: a line of question marks reads as a broken
     * printer and sends somebody to look for a fault that is not there. What is missing is missing
     * because the hardware has no such glyph, and the print view exists for exactly that case.
     */
    private function encode(string $text): string
    {
        $converted = @iconv('UTF-8', 'CP1252//IGNORE', $text);

        return false === $converted ? '' : $converted;
    }
}
