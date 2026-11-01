<?php

namespace Tests\Feature;

use App\Services\Promotions\Boosts;
use Tests\Support\PromotionFixtures;
use Tests\TestCase;

class StaffPromotionTest extends TestCase
{
    use PromotionFixtures;

    public function testPromotionReviewAndFinancialHoldsRequireTheirOwnPermissions(): void
    {
        [$owner] = $this->paidArtist();
        $this->promotionSetup();
        $boost = $this->submittedBoost($owner);
        $finance = $this->makeUser('finance');
        $operator = $this->makeUser('promotion');
        $input = ['version' => $boost->version, 'decision' => 'approve', 'reason' => 'Reviewed advertising rights'];

        $this->browserLogin($finance, 'finance');
        $this->browserPage('/promotions/boosts/' . $boost->id)->assertOk()->assertJsonPath('props.decision_options', ['hold']);
        $this->browserWrite('/promotions/boosts/' . $boost->id . '/decision', $input)->assertForbidden();
        $this->assertSame('pending_review', Boosts::find($boost->id)->status);
        $ledger = $this->browserPage('/promotions/credits?per_page=1')->assertOk();
        $this->assertTrue(count($ledger->json('props.rows.0.links')) === 1);
        $this->assertTrue(str_contains($ledger->json('props.rows.0.links.0.label'), 'Source period'));

        $this->browserLogin($operator, 'promotion_operator');
        $this->browserWrite('/promotions/boosts/' . $boost->id . '/decision', [...$input, 'decision' => 'hold'])->assertForbidden();
        $this->browserWrite('/promotions/boosts/' . $boost->id . '/decision', $input)->assertStatus(303);
        $this->assertSame('scheduled', Boosts::find($boost->id)->status);
        $this->assertBrowserRejected($this->browserWrite('/promotions/boosts/' . $boost->id . '/decision', $input));

        $this->browserLogin($owner, 'admin');
        $page = $this->browserPage('/promotions/boosts/' . $boost->id)->assertOk();
        $page->assertJsonPath('props.decision_url', null)->assertJsonPath('props.remedy_url', null);
        $this->browserWrite('/promotions/boosts/' . $boost->id . '/decision', [...$input, 'version' => Boosts::find($boost->id)->version, 'decision' => 'hold'])->assertForbidden();
        $this->assertFalse((bool) Boosts::find($boost->id)->staff_hold);
    }
}
