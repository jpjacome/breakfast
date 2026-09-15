<?php

namespace Database\Factories;

use App\Enums\AssetVisibility;
use App\Models\BrandAsset;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BrandAsset>
 *
 * Builds the ROW, not the file. Most tests about a brand's folder are about who
 * may reach a file rather than about its bytes, and going through the upload
 * endpoint to ask a permission question means every one of them depends on the
 * uploader working. A test that needs the file on disk puts it there itself —
 * see the download tests, which Storage::fake() and write one line into it.
 */
class BrandAssetFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->word().'.pdf';

        return [
            'client_id' => Client::factory(),
            'title' => ucfirst(fake()->words(2, true)),
            // The safe one, matching the column default and every file that
            // existed before visibility did.
            'visibility' => AssetVisibility::Compartido,
            'disk' => 'local',
            'path' => 'marcas/prueba/assets/'.fake()->uuid().'.pdf',
            'original_name' => $name,
            'mime' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(1024, 5 * 1024 * 1024),
        ];
    }

    /** Breakfast's own: the contract, the pricing sheet, the working notes. */
    public function internal(): static
    {
        return $this->state(['visibility' => AssetVisibility::Interno]);
    }
}
