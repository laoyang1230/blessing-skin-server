<?php

namespace Tests;

use App\Events;
use App\Models\Player;
use App\Models\Texture;
use App\Models\User;
use Blessing\Rejection;
use Event;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class PlayerControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(factory(User::class)->create());
    }

    public function testIndex()
    {
        $filter = Fakes\Filter::fake();

        $this->get('/user/player?pid=5')->assertViewIs('user.player');
        $filter->assertApplied('grid:user.player');
    }

    public function testList()
    {
        $user = factory(User::class)->create();
        $player = factory(Player::class)->create(['uid' => $user->uid]);
        $this->actingAs($user)
            ->get('/user/player/list')
            ->assertJson([$player->toArray()]);
    }

    public function testAdd()
    {
        Event::fake();
        $filter = Fakes\Filter::fake();

        // Without player name
        $this->postJson('/user/player/add')->assertJsonValidationErrors('name');

        // Only A-Za-z0-9_ are allowed
        option(['player_name_rule' => 'official']);
        $this->postJson(
            '/user/player/add',
            ['name' => '角色名']
        )->assertJsonValidationErrors('name');

        // Custom player name rule (regexp)
        option(['player_name_rule' => 'custom']);
        option(['custom_player_name_regexp' => '/^\d+$/']);
        $this->postJson(
            '/user/player/add',
            ['name' => 'yjsnpi']
        )->assertJsonValidationErrors('name');

        // with an existed player name
        option(['player_name_rule' => 'official']);
        $existed = factory(Player::class)->create();
        $this->postJson('/user/player/add', ['name' => $existed->name])
            ->assertJsonValidationErrors('name');

        // Lack of score
        $user = factory(User::class)->create(['score' => 0]);
        $this->actingAs($user)->postJson(
            '/user/player/add',
            ['name' => 'no_score']
        )->assertJson([
            'code' => 7,
            'message' => trans('user.player.add.lack-score'),
        ]);
        $filter->assertApplied('new_player_name', function ($name) {
            $this->assertEquals('no_score', $name);

            return true;
        });
        Event::assertDispatched('player.add.attempt', function ($event, $payload) use ($user) {
            $this->assertEquals('no_score', $payload[0]);
            $this->assertEquals($user->uid, $payload[1]->uid);

            return true;
        });
        Event::assertNotDispatched('player.adding');
        Event::assertNotDispatched('player.added');

        // rejected
        Event::fake();
        $filter->add('can_add_player', function ($can, $name) {
            $this->assertEquals('can', $name);

            return new Rejection('rejected');
        });
        $this->postJson(
            '/user/player/add',
            ['name' => 'can']
        )->assertJson(['code' => 1, 'message' => 'rejected']);
        Event::assertDispatched('player.add.attempt');
        Event::assertNotDispatched('player.adding');
        Event::assertNotDispatched('player.added');

        // Allowed to use CJK characters
        Event::fake();
        Fakes\Filter::fake();
        option(['player_name_rule' => 'cjk']);
        $user = factory(User::class)->create();
        $score = $user->score;
        $this->actingAs($user)->postJson('/user/player/add', [
            'name' => '角色名',
        ])->assertJson([
            'code' => 0,
            'message' => trans('user.player.add.success', ['name' => '角色名']),
        ]);
        Event::assertDispatched('player.add.attempt', function ($event, $payload) use ($user) {
            $this->assertEquals('角色名', $payload[0]);
            $this->assertEquals($user->uid, $payload[1]->uid);

            return true;
        });
        Event::assertDispatched('player.adding', function ($event, $payload) use ($user) {
            $this->assertEquals('角色名', $payload[0]);
            $this->assertEquals($user->uid, $payload[1]->uid);

            return true;
        });
        Event::assertDispatched('player.added', function ($event, $payload) use ($user) {
            $this->assertEquals('角色名', $payload[0]->name);
            $this->assertEquals($user->uid, $payload[1]->uid);

            return true;
        });
        Event::assertDispatched(Events\PlayerWillBeAdded::class);
        Event::assertDispatched(Events\PlayerWasAdded::class);
        $player = Player::where('name', '角色名')->first();
        $this->assertNotNull($player);
        $this->assertEquals($user->uid, $player->uid);
        $this->assertEquals('角色名', $player->name);
        $this->assertEquals(
            $score - option('score_per_player'),
            User::find($user->uid)->score
        );

        // Single player
        option(['single_player' => true]);
        $this->postJson('/user/player/add', ['name' => 'abc'])
            ->assertJson([
                'code' => 1,
                'message' => trans('user.player.add.single'),
            ]);
    }

    public function testDelete()
    {
        Event::fake();
        $filter = Fakes\Filter::fake();

        $user = factory(User::class)->create();
        $player = factory(Player::class)->create(['uid' => $user->uid]);
        $score = $user->score;

        // rejected
        $filter->add('can_delete_player', function ($can, $p) use ($player) {
            $this->assertTrue($player->is($p));

            return new Rejection('rejected');
        });
        $this->actingAs($user)
            ->postJson('/user/player/delete/'.$player->pid)
            ->assertJson(['code' => 1, 'message' => 'rejected']);

        // success
        $filter = Fakes\Filter::fake();
        $this->postJson('/user/player/delete/'.$player->pid)
            ->assertJson([
                'code' => 0,
                'message' => trans('user.player.delete.success', ['name' => $player->name]),
            ]);
        Event::assertDispatched('player.delete.attempt', function ($event, $payload) use ($player, $user) {
            $this->assertEquals($player->pid, $payload[0]->pid);
            $this->assertEquals($user->uid, $payload[1]->uid);

            return true;
        });
        Event::assertDispatched('player.deleting', function ($event, $payload) use ($player, $user) {
            $this->assertEquals($player->pid, $payload[0]->pid);
            $this->assertEquals($user->uid, $payload[1]->uid);

            return true;
        });
        $this->assertNull(Player::find($player->pid));
        Event::assertDispatched('player.deleted', function ($event, $payload) use ($player, $user) {
            $this->assertEquals($player->pid, $payload[0]->pid);
            $this->assertEquals($user->uid, $payload[1]->uid);

            return true;
        });
        Event::assertDispatched(Events\PlayerWillBeDeleted::class);
        Event::assertDispatched(Events\PlayerWasDeleted::class);
        $this->assertEquals(
            $score + option('score_per_player'),
            User::find($user->uid)->score
        );

        // No returning score
        option(['return_score' => false]);
        $player = factory(Player::class)->create();
        $user = $player->user;
        $this->actingAs($user)
            ->postJson('/user/player/delete/'.$player->pid)
            ->assertJson([
                'code' => 0,
                'message' => trans('user.player.delete.success', ['name' => $player->name]),
            ]);
        $this->assertEquals(
            $user->score,
            User::find($user->uid)->score
        );

        // Single player
        option(['single_player' => true]);
        $player = factory(Player::class)->create(['uid' => $user->uid]);
        $this->actingAs($user)
            ->postJson('/user/player/delete/'.$player->pid)
            ->assertJson([
                'code' => 1,
                'message' => trans('user.player.delete.single'),
            ]);
        $this->assertNotNull(Player::find($player->pid));
    }

    public function testRename()
    {
        Event::fake();
        $filter = Fakes\Filter::fake();
        $player = factory(Player::class)->create();
        $user = $player->user;

        // Without new player name
        $this->actingAs($user)
            ->postJson('/user/player/rename/'.$player->pid)
            ->assertJsonValidationErrors('name');

        // Only A-Za-z0-9_ are allowed
        option(['player_name_rule' => 'official']);
        $this->postJson('/user/player/rename/'.$player->pid, ['name' => '角色名'])
            ->assertJsonValidationErrors('name');

        // Other invalid characters
        option(['player_name_rule' => 'cjk']);
        $this->postJson('/user/player/rename/'.$player->pid, ['name' => '\\'])
            ->assertJsonValidationErrors('name');

        // with an existed player name
        $existed = factory(Player::class)->create();
        $this->postJson('/user/player/rename/'.$player->pid, ['name' => $existed->name])
            ->assertJsonValidationErrors('name');

        // Rejected by filter
        $filter = Fakes\Filter::fake();
        $filter->add('user_can_rename_player', function ($can, $p, $name) use ($player) {
            $this->assertTrue($player->is($p));
            $this->assertEquals('new', $name);

            return new Rejection('rejected');
        });
        $name = factory(Player::class)->create()->name;
        $this->postJson('/user/player/rename/'.$player->pid, ['name' => 'new'])
            ->assertJson([
                'code' => 1,
                'message' => 'rejected',
            ]);
        $filter->remove('user_can_rename_player');

        // Success
        Event::fake();
        $pid = $player->pid;
        $this->postJson('/user/player/rename/'.$pid, ['name' => 'new_name'])
            ->assertJson([
                'code' => 0,
                'message' => trans(
                    'user.player.rename.success',
                    ['old' => $player->name, 'new' => 'new_name']
                ),
            ]);
        Event::assertDispatched(Events\PlayerProfileUpdated::class);
        Event::assertDispatched('player.renaming', function ($event, $payload) use ($pid) {
            [$player, $newName] = $payload;
            $this->assertEquals($pid, $player->pid);
            $this->assertEquals('new_name', $newName);

            return true;
        });
        Event::assertDispatched('player.renamed', function ($event, $payload) use ($player) {
            $this->assertTrue($player->fresh()->is($payload[0]));
            $this->assertNotEquals('new_name', $payload[1]->name);

            return true;
        });
        $filter->assertApplied('new_player_name', function ($name) {
            $this->assertEquals('new_name', $name);

            return true;
        });

        // Single player
        option(['single_player' => true]);
        $this->postJson('/user/player/rename/'.$player->pid, ['name' => 'abc'])
            ->assertJson(['code' => 0]);
        $this->assertEquals('abc', $player->user->nickname);
    }

    public function testSetTexture()
    {
        $player = factory(Player::class)->create();
        $user = $player->user;
        $skin = factory(Texture::class)->create();
        $cape = factory(Texture::class)->states('cape')->create();

        // rejected
        $filter = Fakes\Filter::fake();
        $filter->add('can_set_texture', function ($can, $p, $type, $tid) use ($player) {
            $this->assertTrue($player->is($p));
            $this->assertEquals('skin', $type);
            $this->assertEquals(-1, $tid);

            return new Rejection('rejected');
        });
        $this->actingAs($user)
            ->postJson('/user/player/set/'.$player->pid, ['skin' => -1])
            ->assertJson(['code' => 1, 'message' => 'rejected']);

        // Set a not-existed texture
        Fakes\Filter::fake();
        $this->postJson('/user/player/set/'.$player->pid, ['skin' => -1])
            ->assertJson([
                'code' => 1,
                'message' => trans('skinlib.non-existent'),
            ]);

        // Set for "skin" type
        Event::fake();
        $this->postJson('/user/player/set/'.$player->pid, ['skin' => $skin->tid])
            ->assertJson([
                'code' => 0,
                'message' => trans('user.player.set.success', ['name' => $player->name]),
            ]);
        $this->assertEquals($skin->tid, Player::find($player->pid)->tid_skin);
        Event::assertDispatched('player.texture.updating', function ($event, $payload) use ($player, $skin) {
            $this->assertEquals($player->pid, $payload[0]->pid);
            $this->assertEquals($skin->tid, $payload[1]->tid);

            return true;
        });
        Event::assertDispatched('player.texture.updated', function ($event, $payload) use ($player, $skin) {
            $this->assertEquals($player->pid, $payload[0]->pid);
            $this->assertEquals($skin->tid, $payload[0]->tid_skin);
            $this->assertEquals($skin->tid, $payload[1]->tid);

            return true;
        });

        // Set for "cape" type
        Event::fake();
        $this->postJson('/user/player/set/'.$player->pid, ['cape' => $cape->tid])
            ->assertJson([
                'code' => 0,
                'message' => trans('user.player.set.success', ['name' => $player->name]),
            ]);
        $this->assertEquals($cape->tid, Player::find($player->pid)->tid_cape);
    }

    public function testClearTexture()
    {
        Event::fake();
        $player = factory(Player::class)->create();
        $user = $player->user;

        $player->tid_skin = 1;
        $player->tid_cape = 2;
        $player->save();
        $player->refresh();

        // rejected
        $filter = Fakes\Filter::fake();
        $filter->add('can_clear_texture', function ($can, $p, $type) use ($player) {
            $this->assertTrue($player->is($p));
            $this->assertEquals('skin', $type);

            return new Rejection('rejected');
        });
        $this->actingAs($user)
            ->postJson('/user/player/texture/clear/'.$player->pid, ['skin' => true])
            ->assertJson(['code' => 1, 'message' => 'rejected']);

        // success
        Fakes\Filter::fake();
        $this->postJson('/user/player/texture/clear/'.$player->pid, [
                'skin' => true,    // "1" stands for "true"
                'cape' => true,
                'nope' => true,    // invalid texture type is acceptable
            ])->assertJson([
                'code' => 0,
                'message' => trans('user.player.clear.success', ['name' => $player->name]),
            ]);
        $this->assertEquals(0, Player::find($player->pid)->tid_skin);
        $this->assertEquals(0, Player::find($player->pid)->tid_cape);
        Event::assertDispatched(Events\PlayerProfileUpdated::class);

        Event::fake();
        $this->postJson('/user/player/texture/clear/'.$player->pid, ['type' => ['skin']])
            ->assertJson(['code' => 0]);
        Event::assertDispatched('player.texture.resetting', function ($event, $payload) use ($player) {
            $this->assertEquals($player->pid, $payload[0]->pid);
            $this->assertEquals('skin', $payload[1]);

            return true;
        });
        Event::assertDispatched('player.texture.reset', function ($event, $payload) use ($player) {
            $this->assertEquals($player->pid, $payload[0]->pid);
            $this->assertEquals('skin', $payload[1]);

            return true;
        });

        Event::fake();
        $this->postJson('/user/player/texture/clear/'.$player->pid, ['type' => ['cape']])
            ->assertJson(['code' => 0]);
        Event::assertDispatched('player.texture.resetting', function ($event, $payload) use ($player) {
            $this->assertEquals($player->pid, $payload[0]->pid);
            $this->assertEquals('cape', $payload[1]);

            return true;
        });
        Event::assertDispatched('player.texture.reset', function ($event, $payload) use ($player) {
            $this->assertEquals($player->pid, $payload[0]->pid);
            $this->assertEquals('cape', $payload[1]);

            return true;
        });
    }

    public function testBind()
    {
        Event::fake();
        option(['single_player' => true]);
        $user = factory(User::class)->create();

        $this->actingAs($user)
            ->postJson('/user/player/bind')
            ->assertJsonValidationErrors('player');

        $this->postJson('/user/player/bind', ['player' => 'abc'])
            ->assertJson([
                'code' => 0,
                'message' => trans('user.player.bind.success'),
            ]);
        Event::assertDispatched('player.adding', function ($event, $payload) use ($user) {
            $this->assertEquals('abc', $payload[0]);
            $this->assertEquals($user->uid, $payload[1]->uid);

            return true;
        });
        Event::assertDispatched('player.added', function ($event, $payload) use ($user) {
            $this->assertEquals('abc', $payload[0]->name);
            $this->assertEquals($user->uid, $payload[1]->uid);

            return true;
        });
        Event::assertDispatched(Events\PlayerWillBeAdded::class);
        Event::assertDispatched(Events\PlayerWasAdded::class);
        $player = Player::where('name', 'abc')->first();
        $this->assertNotNull($player);
        $this->assertEquals($user->uid, $player->uid);
        $this->assertEquals('abc', $player->name);
        $user->refresh();
        $this->assertEquals('abc', $user->nickname);

        $player2 = factory(Player::class)->create();
        $player3 = factory(Player::class)->create(['uid' => $user->uid]);
        $this->postJson('/user/player/bind', ['player' => $player2->name])
            ->assertJson([
                'code' => 1,
                'message' => trans('user.player.rename.repeated'),
            ]);

        $this->postJson('/user/player/bind', ['player' => $player->name])
            ->assertJson(['code' => 0]);
        $this->assertNull(Player::where('name', $player3->name)->first());
    }
}
