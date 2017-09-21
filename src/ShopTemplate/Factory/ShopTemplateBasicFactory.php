<?php

namespace Shopware\ShopTemplate\Factory;

use Shopware\Context\Struct\TranslationContext;
use Shopware\Framework\Factory\Factory;
use Shopware\Search\QueryBuilder;
use Shopware\Search\QuerySelection;
use Shopware\ShopTemplate\Struct\ShopTemplateBasicStruct;

class ShopTemplateBasicFactory extends Factory
{
    const ROOT_NAME = 'shop_template';
    const EXTENSION_NAMESPACE = 'shopTemplate';

    const FIELDS = [
       'id' => 'id',
       'uuid' => 'uuid',
       'template' => 'template',
       'name' => 'name',
       'description' => 'description',
       'author' => 'author',
       'license' => 'license',
       'esi' => 'esi',
       'style_support' => 'style_support',
       'version' => 'version',
       'emotion' => 'emotion',
       'plugin_id' => 'plugin_id',
       'plugin_uuid' => 'plugin_uuid',
       'parent_id' => 'parent_id',
       'parent_uuid' => 'parent_uuid',
    ];

    public function hydrate(
        array $data,
        ShopTemplateBasicStruct $shopTemplate,
        QuerySelection $selection,
        TranslationContext $context
    ): ShopTemplateBasicStruct {
        $shopTemplate->setId((int) $data[$selection->getField('id')]);
        $shopTemplate->setUuid((string) $data[$selection->getField('uuid')]);
        $shopTemplate->setTemplate((string) $data[$selection->getField('template')]);
        $shopTemplate->setName((string) $data[$selection->getField('name')]);
        $shopTemplate->setDescription(isset($data[$selection->getField('description')]) ? (string) $data[$selection->getField('description')] : null);
        $shopTemplate->setAuthor(isset($data[$selection->getField('author')]) ? (string) $data[$selection->getField('author')] : null);
        $shopTemplate->setLicense(isset($data[$selection->getField('license')]) ? (string) $data[$selection->getField('license')] : null);
        $shopTemplate->setEsi((bool) $data[$selection->getField('esi')]);
        $shopTemplate->setStyleSupport((bool) $data[$selection->getField('style_support')]);
        $shopTemplate->setVersion((int) $data[$selection->getField('version')]);
        $shopTemplate->setEmotion((bool) $data[$selection->getField('emotion')]);
        $shopTemplate->setPluginId(isset($data[$selection->getField('plugin_id')]) ? (int) $data[$selection->getField('plugin_id')] : null);
        $shopTemplate->setPluginUuid(isset($data[$selection->getField('plugin_uuid')]) ? (string) $data[$selection->getField('plugin_uuid')] : null);
        $shopTemplate->setParentId(isset($data[$selection->getField('parent_id')]) ? (int) $data[$selection->getField('parent_id')] : null);
        $shopTemplate->setParentUuid(isset($data[$selection->getField('parent_uuid')]) ? (string) $data[$selection->getField('parent_uuid')] : null);

        foreach ($this->getExtensions() as $extension) {
            $extension->hydrate($shopTemplate, $data, $selection, $context);
        }

        return $shopTemplate;
    }

    public function getFields(): array
    {
        $fields = array_merge(self::FIELDS, parent::getFields());

        return $fields;
    }

    public function joinDependencies(QuerySelection $selection, QueryBuilder $query, TranslationContext $context): void
    {
        if ($translation = $selection->filter('translation')) {
            $query->leftJoin(
                $selection->getRootEscaped(),
                'shop_template_translation',
                $translation->getRootEscaped(),
                sprintf(
                    '%s.shop_template_uuid = %s.uuid AND %s.language_uuid = :languageUuid',
                    $translation->getRootEscaped(),
                    $selection->getRootEscaped(),
                    $translation->getRootEscaped()
                )
            );
            $query->setParameter('languageUuid', $context->getShopUuid());
        }

        $this->joinExtensionDependencies($selection, $query, $context);
    }

    public function getAllFields(): array
    {
        $fields = array_merge(self::FIELDS, $this->getExtensionFields());

        return $fields;
    }

    protected function getRootName(): string
    {
        return self::ROOT_NAME;
    }

    protected function getExtensionNamespace(): string
    {
        return self::EXTENSION_NAMESPACE;
    }
}