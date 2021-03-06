<?php

namespace OpenDominion\Tests\Unit\Services\Action;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use OpenDominion\Models\Dominion;
use OpenDominion\Models\Race;
use OpenDominion\Models\Round;
use OpenDominion\Services\Dominion\Actions\EspionageActionService;
use OpenDominion\Tests\AbstractBrowserKitTestCase;

class EspionageActionServiceTest extends AbstractBrowserKitTestCase
{
    use DatabaseTransactions;

    /** @var EspionageActionService */
    protected $espionageActionService;

    /** @var Round */
    protected $round;

    /** @var Dominion */
    protected $dominion;

    /** @var Dominion */
    protected $target;

    public function setUp()
    {
        parent::setUp();

        $user = $this->createAndImpersonateUser();
        $this->round = $this->createRound('last week');

        $this->dominion = $this->createDominion($user, $this->round, Race::where('name', 'Halfling')->firstOrFail());
        $this->dominion->protection_ticks_remaining = 0;

        $targetUser = $this->createUser();
        $this->target = $this->createDominion($targetUser, $this->round, Race::where('name', 'Nomad')->firstOrFail());
        $this->target->protection_ticks_remaining = 0;

        $this->espionageActionService = $this->app->make(EspionageActionService::class);

        global $mockRandomChance;
        $mockRandomChance = true;
    }

    public function testPerformOperation_SameSpa_LoseQuarterPercent()
    {
        // Arrange
        $this->dominion->military_spies = 10000;
        $this->target->military_spies = 10000;

        // Act
        $this->espionageActionService->performOperation($this->dominion, 'barracks_spy', $this->target);

        // Assert
        $this->assertEquals(9975, $this->dominion->military_spies);
    }

    public function testPerformOperation_MuchLowerSpa_LoseMaxOnePercent()
    {
        // Arrange
        $this->dominion->military_spies = 10000;
        $this->target->military_spies = 10000000;

        // Act
        $this->espionageActionService->performOperation($this->dominion, 'barracks_spy', $this->target);

        // Assert
        $this->assertEquals(9900, $this->dominion->military_spies);
    }

    public function testPerformOperation_MuchHigherSpa_LoseQuarterPercent()
    {
        // Arrange
        $this->dominion->military_spies = 10000;
        $this->target->military_spies = 100;

        // Act
        $this->espionageActionService->performOperation($this->dominion, 'barracks_spy', $this->target);

        // Assert
        $this->assertEquals(9975, $this->dominion->military_spies);
    }

    public function testPerformOperation_SameSpa_LoseMilitary()
    {
        // Arrange
        $this->dominion->military_unit3 = 50000;
        $this->target->military_spies = 10000;

        // Act
        $this->espionageActionService->performOperation($this->dominion, 'barracks_spy', $this->target);

        // Assert
        $this->assertEquals(49988, $this->dominion->military_unit3);
    }
}
