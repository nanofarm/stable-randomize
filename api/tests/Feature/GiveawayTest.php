<?php

namespace Tests\Feature;

use App\Models\Giveaway;
use App\Models\Participant;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class GiveawayTest extends TestCase
{
    use RefreshDatabase;

    public function test_draw_picks_correct_number_of_winners(): void
    {
        $g = Giveaway::create([
            'title'         => 'Test',
            'creator_id'    => 1,
            'creator_name'  => 'A',
            'status'        => 'active',
            'winners_count' => 3,
        ]);

        foreach (range(1, 10) as $i) {
            Participant::create([
                'giveaway_id' => $g->id,
                'user_id'     => 1000 + $i,
                'user_name'   => "user_$i",
            ]);
        }

        $winners = $g->drawWinners();

        $this->assertCount(3, $winners);
        $this->assertEquals('finished', $g->fresh()->status);
        $this->assertEquals(3, $g->participants()->where('is_winner', true)->count());
    }

    public function test_draw_returns_empty_when_no_participants(): void
    {
        $g = Giveaway::create([
            'title'         => 'Empty',
            'creator_id'    => 1,
            'creator_name'  => 'A',
            'status'        => 'active',
            'winners_count' => 1,
        ]);

        $this->assertSame([], $g->drawWinners());
    }
}
