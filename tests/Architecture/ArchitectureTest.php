<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\ClassNamespace;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * The layering, enforced rather than described.
 *
 * Every rule here corresponds to a decision in docs/adr/ whose whole value depends
 * on nobody undoing it later. Until now they were held up by convention and by
 * commit messages, which is exactly the kind of guarantee that erodes: a single
 * `use Illuminate\...` in the domain, added in good faith to solve something
 * unrelated, quietly converts a framework-free aggregate into an ordinary one and
 * no test notices.
 *
 * These are not PHPUnit tests. phpat compiles them into PHPStan rules, so they run
 * inside the analysis step that already exists — no extra CI job, and a violation
 * is reported at the offending line like any other error.
 */
final class ArchitectureTest
{
    /**
     * The point of ADR 0001. If the domain can reach the framework, the invariants
     * stop being unbypassable: Eloquent in particular puts an unguarded status
     * write one line away from any caller.
     */
    public function test_the_domain_depends_on_no_framework(): Rule
    {
        return PHPat::rule()
            ->classes($this->domain())
            ->shouldNotDependOn()
            ->classes(...$this->framework());
    }

    /**
     * Stronger than it looks and worth pinning: the application layer currently has
     * no framework import at all. That is what lets the whole send workflow be
     * exercised with no container and no database, through the transaction port.
     */
    public function test_the_application_layer_depends_on_no_framework(): Rule
    {
        return PHPat::rule()
            ->classes($this->application())
            ->shouldNotDependOn()
            ->classes(...$this->framework());
    }

    /** Dependencies point inwards. The domain is the centre, so it points nowhere. */
    public function test_the_domain_depends_on_nothing_above_it(): Rule
    {
        return PHPat::rule()
            ->classes($this->domain())
            ->shouldNotDependOn()
            ->classes($this->application(), $this->infrastructure(), $this->presentation());
    }

    public function test_the_application_layer_does_not_reach_outwards(): Rule
    {
        return PHPat::rule()
            ->classes($this->application())
            ->shouldNotDependOn()
            ->classes($this->infrastructure(), $this->presentation());
    }

    /**
     * Controllers go through the application services, never straight to a
     * repository or an adapter. Breaking this is how an HTTP handler ends up owning
     * a piece of the workflow that the delivery webhook then does not share.
     */
    public function test_presentation_does_not_reach_into_infrastructure(): Rule
    {
        return PHPat::rule()
            ->classes($this->presentation())
            ->shouldNotDependOn()
            ->classes($this->infrastructure());
    }

    /**
     * The claim made in ADR 0001 and in the models' own docblocks: they are named
     * *Model and never leave this namespace. The mapper is the only crossing point,
     * which is what keeps the sixty lines of mapping worth paying for.
     */
    public function test_the_eloquent_models_never_leave_infrastructure(): Rule
    {
        return PHPat::rule()
            ->classes($this->invoices())
            ->excluding($this->infrastructure())
            ->shouldNotDependOn()
            ->classes(new ClassNamespace('Modules\Invoices\Infrastructure\Persistence\Eloquent', false));
    }

    /**
     * The entire coupling surface between the two modules is the published Api
     * layer: the notifier adapters and the delivery listener. Reaching into
     * Notifications' internals would make this module depend on a mock the brief
     * explicitly says not to treat as a reference.
     */
    public function test_invoices_touches_only_the_published_notifications_api(): Rule
    {
        return PHPat::rule()
            ->classes($this->invoices())
            ->shouldNotDependOn()
            ->classes(
                new ClassNamespace('Modules\Notifications\Application', false),
                new ClassNamespace('Modules\Notifications\Infrastructure', false),
                new ClassNamespace('Modules\Notifications\Presentation', false),
            );
    }

    /**
     * And the coupling is one-directional. Notifications shipped with the task
     * knowing nothing about invoices, and it has to stay that way or the two
     * modules become one.
     */
    public function test_notifications_knows_nothing_about_invoices(): Rule
    {
        return PHPat::rule()
            ->classes(new ClassNamespace('Modules\Notifications', false))
            ->shouldNotDependOn()
            ->classes($this->invoices());
    }

    private function invoices(): ClassNamespace
    {
        return new ClassNamespace('Modules\Invoices', false);
    }

    private function domain(): ClassNamespace
    {
        return new ClassNamespace('Modules\Invoices\Domain', false);
    }

    private function application(): ClassNamespace
    {
        return new ClassNamespace('Modules\Invoices\Application', false);
    }

    private function infrastructure(): ClassNamespace
    {
        return new ClassNamespace('Modules\Invoices\Infrastructure', false);
    }

    private function presentation(): ClassNamespace
    {
        return new ClassNamespace('Modules\Invoices\Presentation', false);
    }

    /**
     * Carbon and Symfony are listed beside Illuminate deliberately. Laravel is not
     * the only way a framework leaks in — a Carbon return type in the domain is the
     * same mistake with a different package name. Ramsey\Uuid is absent on purpose:
     * it is a value-object library, and UuidInterface in the domain is a type, not
     * a framework dependency.
     *
     * @return list<ClassNamespace>
     */
    private function framework(): array
    {
        return [
            new ClassNamespace('Illuminate', false),
            new ClassNamespace('Carbon', false),
            new ClassNamespace('Symfony', false),
        ];
    }
}
