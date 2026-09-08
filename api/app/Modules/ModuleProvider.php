<?php

namespace App\Modules;

use App\Domain\Sites\Payments\PaymentGateway;
use App\Modules\Contracts\MessageChannel;
use App\Modules\Contracts\ReportSource;

/**
 * What a module extends, and the only surface it has to do it through (ADR-0004 §1).
 *
 * A module does not "hook into" the platform. It answers a fixed set of questions, each returning
 * a typed thing, and the platform decides what to do with the answers. Everything a module could
 * want that is not on this list is a core change and an amendment to the ADR — deliberately, so
 * that "what can a module do" stays a question with an answer.
 *
 * Note what is absent: no access to the container, no route registration, no migrations, no
 * database connection. A module reacts to events and returns objects; the inventory guarantees in
 * ADR-0002 are the product, and a module that could reach around them would make them opinions.
 */
abstract class ModuleProvider
{
    public function __construct(protected readonly ModuleContext $context) {}

    /** @return list<PaymentGateway> */
    public function payments(): array
    {
        return [];
    }

    /** @return list<MessageChannel> */
    public function messaging(): array
    {
        return [];
    }

    /** @return list<ReportSource> */
    public function reports(): array
    {
        return [];
    }

    /**
     * Block types this module adds to the page editor.
     *
     * @return array<string, array<string, mixed>> keyed by block type
     */
    public function blocks(): array
    {
        return [];
    }

    /** @return array<string, array<string, mixed>> keyed by theme key */
    public function themes(): array
    {
        return [];
    }

    /** @return list<array{key:string,label_key:string,icon:string}> */
    public function panel(): array
    {
        return [];
    }

    /**
     * Domain events this module wants to hear about, and what to do.
     *
     * Failures here are caught, recorded against the module, and shown as its health — a listener
     * must never fail the request that triggered it (ADR-0004 §5).
     *
     * @return array<class-string, callable>
     */
    public function listeners(): array
    {
        return [];
    }

    /**
     * A chance to say "I am configured wrong" before an organiser finds out from a buyer.
     *
     * Returns translation keys for anything missing or nonsensical. The platform will not let a
     * module be enabled while this returns anything.
     *
     * @return list<string>
     */
    public function validate(): array
    {
        return [];
    }
}
