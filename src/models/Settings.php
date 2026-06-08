<?php

declare(strict_types=1);

namespace SiteAssetRouter\models;

use craft\base\Model;

class Settings extends Model
{
    /** @var string[] Volume handles to exclude from site routing */
    public array $excludedVolumes = [];

    public function rules(): array
    {
        return [
            ['excludedVolumes', 'each', 'rule' => ['string']],
            ['excludedVolumes', 'default', 'value' => []],
        ];
    }
}
