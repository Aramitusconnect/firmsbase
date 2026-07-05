<?php

namespace Tests\Feature\Signature\Hashes;

use App\Models\DocumentHash;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentHashIsImmutableTest extends TestCase
{
    use RefreshDatabase;

    public function test_updating_an_existing_document_hash_throws(): void
    {
        $hash = DocumentHash::factory()->create();

        $this->expectException(\LogicException::class);
        $hash->update(['hash_value' => 'tampered-value']);
    }

    public function test_deleting_an_existing_document_hash_throws(): void
    {
        $hash = DocumentHash::factory()->create();

        $this->expectException(\LogicException::class);
        $hash->delete();
    }

    public function test_creating_a_new_document_hash_still_works(): void
    {
        $hash = DocumentHash::factory()->create();

        $this->assertDatabaseHas('document_hashes', ['id' => $hash->id]);
    }
}
