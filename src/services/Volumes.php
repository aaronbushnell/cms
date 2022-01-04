<?php

namespace craft\services;

use Craft;
use craft\base\Field;
use craft\base\MemoizableArray;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\events\ConfigEvent;
use craft\events\FieldEvent;
use craft\events\VolumeEvent;
use craft\fs\Temp;
use craft\helpers\ArrayHelper;
use craft\helpers\Db;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use craft\models\Volume;
use craft\models\VolumeFolder;
use craft\records\Volume as AssetVolumeRecord;
use craft\records\VolumeFolder as VolumeFolderRecord;
use Throwable;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Class AssetVolumesService
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @copyright Copyright (c) 2014, Pixel & Tonic, Inc.
 * @license http://craftcms.com/license Craft License Agreement
 * @see http://craftcms.com
 * @package craft.app.services
 * @since 3.0.0
 *
 * @property-read int[] $allVolumeIds
 * @property-read string[] $allVolumeTypes
 * @property-read int $totalVolumes
 * @property-read array $viewableVolumeIds
 * @property-read Volume[] $allVolumes
 * @property-read int[] $publicVolumeIds
 * @property-read int $totalViewableVolumes
 * @property-read Volume[] $publicVolumes
 * @property-read Volume[] $viewableVolumes
 */
class Volumes extends Component
{
    /**
     * @event VolumeEvent The event that is triggered before an Asset volume is saved.
     */
    public const EVENT_BEFORE_SAVE_VOLUME = 'beforeSaveVolume';

    /**
     * @event VolumeEvent The event that is triggered after an Asset volume is saved.
     */
    public const EVENT_AFTER_SAVE_VOLUME = 'afterSaveVolume';

    /**
     * @event VolumeEvent The event that is triggered before an Asset volume is deleted.
     */
    public const EVENT_BEFORE_DELETE_VOLUME = 'beforeDeleteVolume';

    /**
     * @event VolumeEvent The event that is triggered before a volume delete is applied to the database.
     * @since 3.1.0
     */
    public const EVENT_BEFORE_APPLY_VOLUME_DELETE = 'beforeApplyVolumeDelete';

    /**
     * @event VolumeEvent The event that is triggered after a Asset volume is deleted.
     */
    public const EVENT_AFTER_DELETE_VOLUME = 'afterDeleteVolume';

    /**
     * @var MemoizableArray<Volume>|null
     * @see _volumes()
     */
    private ?MemoizableArray $_volumes = null;

    /**
     * @var array|null Volume setting overrides
     */
    private ?array $_overrides = null;

    /**
     * Serializer
     *
     * @since 3.5.14
     */
    public function __serialize(): array
    {
        $vars = get_object_vars($this);
        unset($vars['_volumes']);
        return $vars;
    }

    // Volumes
    // -------------------------------------------------------------------------

    /**
     * Returns all of the volume IDs.
     *
     * @return int[]
     */
    public function getAllVolumeIds(): array
    {
        return ArrayHelper::getColumn($this->getAllVolumes(), 'id', false);
    }

    /**
     * Returns all volume IDs that are viewable by the current user.
     *
     * @return array
     */
    public function getViewableVolumeIds(): array
    {
        return ArrayHelper::getColumn($this->getViewableVolumes(), 'id', false);
    }

    /**
     * Returns all volumes that are viewable by the current user.
     *
     * @return Volume[]
     */
    public function getViewableVolumes(): array
    {
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            return $this->getAllVolumes();
        }

        $userSession = Craft::$app->getUser();
        return ArrayHelper::where($this->getAllVolumes(), function(Volume $volume) use ($userSession) {
            return $userSession->checkPermission('viewVolume:' . $volume->uid);
        }, true, true, false);
    }

    /**
     * Returns all volume IDs that have public URLs.
     *
     * @return int[]
     */
    public function getPublicVolumeIds(): array
    {
        return ArrayHelper::getColumn($this->getPublicVolumes(), 'id', false);
    }

    /**
     * Returns all volumes that have public URLs.
     *
     * @return Volume[]
     */
    public function getPublicVolumes(): array
    {
        return $this->_volumes()->where('hasUrls')->all();
    }

    /**
     * Returns the total number of volumes.
     *
     * @return int
     */
    public function getTotalVolumes(): int
    {
        return count($this->getAllVolumes());
    }

    /**
     * Returns the total number of volumes that are viewable by the current user.
     *
     * @return int
     */
    public function getTotalViewableVolumes(): int
    {
        return count($this->getViewableVolumes());
    }

    /**
     * Returns a memoizable array of all volumes.
     *
     * @return MemoizableArray<Volume>
     */
    private function _volumes(): MemoizableArray
    {
        if (!isset($this->_volumes)) {
            $volumes = [];
            foreach ($this->_createVolumeQuery()->all() as $result) {
                $volumes[] = Craft::createObject(Volume::class, [$result]);
            }
            $this->_volumes = new MemoizableArray($volumes);
        }

        return $this->_volumes;
    }

    /**
     * Returns all volumes.
     *
     * @return Volume[]
     */
    public function getAllVolumes(): array
    {
        return $this->_volumes()->all();
    }

    /**
     * Returns a volume by its ID.
     *
     * @param int $volumeId
     * @return Volume|null
     */
    public function getVolumeById(int $volumeId): ?Volume
    {
        return $this->_volumes()->firstWhere('id', $volumeId);
    }

    /**
     * @return Volume
     * @throws InvalidConfigException
     */
    public function getTemporaryVolume(): Volume
    {
        $volume = new Volume([
            'name' => Craft::t('app', 'Temporary volume')
        ]);

        $volume->setFilesystem(Craft::createObject(Temp::class));

        return $volume;
    }

    /**
     * Returns a volume by its UID.
     *
     * @param string $volumeUid
     * @return Volume|null
     */
    public function getVolumeByUid(string $volumeUid): ?Volume
    {
        return $this->_volumes()->firstWhere('uid', $volumeUid, true);
    }

    /**
     * Returns a volume by its handle.
     *
     * @param string $handle
     * @return Volume|null
     */
    public function getVolumeByHandle(string $handle): ?Volume
    {
        return $this->_volumes()->firstWhere('handle', $handle, true);
    }

    /**
     * Returns the field layout config for the given volume.
     *
     * @param Volume $volume
     * @return array
     * @since 3.5.0
     */
    public function createVolumeConfig(Volume $volume): array
    {
        $config = [
            'name' => $volume->name,
            'handle' => $volume->handle,
            'filesystem' => $volume->filesystem,
            'titleTranslationMethod' => $volume->titleTranslationMethod,
            'titleTranslationKeyFormat' => $volume->titleTranslationKeyFormat ?: null,
            'sortOrder' => (int)$volume->sortOrder,
        ];

        if (
            ($fieldLayout = $volume->getFieldLayout()) &&
            ($fieldLayoutConfig = $fieldLayout->getConfig())
        ) {
            $config['fieldLayouts'] = [
                $fieldLayout->uid => $fieldLayoutConfig,
            ];
        }

        return $config;
    }

    /**
     * Creates or updates a volume.
     *
     * ---
     *
     * ```php
     * use craft\volumes\Local;
     *
     * $volume = new Local([
     *     'name' => 'Content Images',
     *     'handle' => 'contentImages',
     *     'filesystem' => 'localFs',
     * ]);
     *
     * if (!Craft::$app->volumes->saveVolume(($volume))) {
     *     throw new Exception('Couldn’t save volume.');
     * }
     * ```
     *
     * @param Volume $volume the volume to be saved.
     * @param bool $runValidation Whether the volume should be validated
     * @return bool Whether the volume was saved successfully
     * @throws Throwable
     */
    public function saveVolume(Volume $volume, bool $runValidation = true): bool
    {
        $isNewVolume = !$volume->id;

        // Fire a 'beforeSaveVolume' event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_SAVE_VOLUME)) {
            $this->trigger(self::EVENT_BEFORE_SAVE_VOLUME, new VolumeEvent([
                'volume' => $volume,
                'isNew' => $isNewVolume,
            ]));
        }

        if ($runValidation && !$volume->validate()) {
            Craft::info('Volume not saved due to validation error.', __METHOD__);
            return false;
        }

        if ($isNewVolume) {
            $volume->uid = StringHelper::UUID();
            $volume->sortOrder = (new Query())
                    ->from([Table::VOLUMES])
                    ->max('[[sortOrder]]') + 1;
        } else if (!$volume->uid) {
            $volume->uid = Db::uidById(Table::VOLUMES, $volume->id);
        }

        $configPath = ProjectConfig::PATH_VOLUMES . '.' . $volume->uid;
        $configData = $this->createVolumeConfig($volume);
        Craft::$app->getProjectConfig()->set($configPath, $configData, "Save the “{$volume->handle}” volume");

        if ($isNewVolume) {
            $volume->id = Db::idByUid(Table::VOLUMES, $volume->uid);
        }

        return true;
    }

    /**
     * Handle volume change
     *
     * @param ConfigEvent $event
     */
    public function handleChangedVolume(ConfigEvent $event): void
    {
        $volumeUid = $event->tokenMatches[0];
        $data = $event->newValue;

        // Make sure fields are processed
        ProjectConfigHelper::ensureAllFieldsProcessed();

        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            $volumeRecord = $this->_getVolumeRecord($volumeUid, true);
            $isNewVolume = $volumeRecord->getIsNewRecord();

            $volumeRecord->name = $data['name'];
            $volumeRecord->handle = $data['handle'];
            $volumeRecord->filesystem = $data['filesystem'];
            $volumeRecord->sortOrder = $data['sortOrder'];
            $volumeRecord->titleTranslationMethod = $data['titleTranslationMethod'] ?? Field::TRANSLATION_METHOD_SITE;
            $volumeRecord->titleTranslationKeyFormat = $data['titleTranslationKeyFormat'] ?? null;
            $volumeRecord->uid = $volumeUid;

            if (!empty($data['fieldLayouts'])) {
                // Save the field layout
                $layout = FieldLayout::createFromConfig(reset($data['fieldLayouts']));
                $layout->id = $volumeRecord->fieldLayoutId;
                $layout->type = Asset::class;
                $layout->uid = key($data['fieldLayouts']);
                Craft::$app->getFields()->saveLayout($layout);
                $volumeRecord->fieldLayoutId = $layout->id;
            } else if ($volumeRecord->fieldLayoutId) {
                // Delete the field layout
                Craft::$app->getFields()->deleteLayoutById($volumeRecord->fieldLayoutId);
                $volumeRecord->fieldLayoutId = null;
            }

            // Save the volume
            if ($wasTrashed = (bool)$volumeRecord->dateDeleted) {
                $volumeRecord->restore();
            } else {
                $volumeRecord->save(false);
            }

            $assetsService = Craft::$app->getAssets();
            $rootFolder = $assetsService->findFolder([
                'volumeId' => $volumeRecord->id,
                'parentId' => ':empty:',
            ]);

            if ($rootFolder === null) {
                $rootFolderRecord = new VolumeFolderRecord([
                    'volumeId' => $volumeRecord->id,
                    'parentId' => null,
                    'path' => '',
                    'name' => $volumeRecord->name,
                ]);

                $rootFolderRecord->save();
            } else {
                $rootFolder->name = $volumeRecord->name;
                $assetsService->storeFolderRecord($rootFolder);
            }

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        // Clear caches
        $this->_volumes = null;

        $volume = $this->getVolumeById($volumeRecord->id);

        if ($wasTrashed) {
            // Restore the assets that were deleted with the volume
            $assets = Asset::find()
                ->volumeId($volumeRecord->id)
                ->trashed()
                ->andWhere(['assets.deletedWithVolume' => true])
                ->all();
            Craft::$app->getElements()->restoreElements($assets);
        }

        // Fire an 'afterSaveVolume' event
        if ($this->hasEventHandlers(self::EVENT_AFTER_SAVE_VOLUME)) {
            $this->trigger(self::EVENT_AFTER_SAVE_VOLUME, new VolumeEvent([
                'volume' => $this->getVolumeById($volumeRecord->id),
                'isNew' => $isNewVolume,
            ]));
        }

        // Invalidate asset caches
        Craft::$app->getElements()->invalidateCachesForElementType(Asset::class);
    }

    /**
     * Reorders asset volumes.
     *
     * @param array $volumeIds
     * @return bool
     * @throws Throwable
     */
    public function reorderVolumes(array $volumeIds): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();

        $uidsByIds = Db::uidsByIds(Table::VOLUMES, $volumeIds);

        foreach ($volumeIds as $volumeOrder => $volumeId) {
            if (!empty($uidsByIds[$volumeId])) {
                $volumeUid = $uidsByIds[$volumeId];
                $projectConfig->set(ProjectConfig::PATH_VOLUMES . '.' . $volumeUid . '.sortOrder', $volumeOrder + 1, "Reorder volumes");
            }
        }

        return true;
    }

    /**
     * Ensures a top level folder exists that matches the model.
     *
     * @param Volume $volume
     * @return VolumeFolder
     */
    public function ensureTopFolder(Volume $volume): VolumeFolder
    {
        $assetsService = Craft::$app->getAssets();
        $folder = $assetsService->findFolder([
            'name' => $volume->name,
            'volumeId' => $volume->id,
        ]);

        if ($folder === null) {
            $folder = new VolumeFolder();
            $folder->volumeId = $volume->id;
            $folder->parentId = null;
            $folder->name = $volume->name;
            $folder->path = '';
            $assetsService->storeFolderRecord($folder);
        }

        return $folder;
    }

    /**
     * Deletes an asset volume by its ID.
     *
     * @param int $volumeId
     * @return bool
     * @throws Throwable
     */
    public function deleteVolumeById(int $volumeId): bool
    {
        $volume = $this->getVolumeById($volumeId);

        if (!$volume) {
            return false;
        }

        return $this->deleteVolume($volume);
    }

    /**
     * Deletes an asset volume.
     *
     * @param Volume $volume The volume to delete
     * @return bool
     * @throws Throwable
     */
    public function deleteVolume(Volume $volume): bool
    {
        // Fire a 'beforeDeleteVolume' event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_DELETE_VOLUME)) {
            $this->trigger(self::EVENT_BEFORE_DELETE_VOLUME, new VolumeEvent([
                'volume' => $volume,
            ]));
        }

        if (!$volume->beforeDelete()) {
            return false;
        }

        Craft::$app->getProjectConfig()->remove(ProjectConfig::PATH_VOLUMES . '.' . $volume->uid, "Delete the “{$volume->handle}” volume");
        return true;
    }

    /**
     * Handle volume getting deleted
     *
     * @param ConfigEvent $event
     */
    public function handleDeletedVolume(ConfigEvent $event): void
    {
        $uid = $event->tokenMatches[0];
        $volumeRecord = $this->_getVolumeRecord($uid);

        if ($volumeRecord->getIsNewRecord()) {
            return;
        }

        $volume = $this->getVolumeById($volumeRecord->id);

        // Fire a 'beforeApplyVolumeDelete' event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_APPLY_VOLUME_DELETE)) {
            $this->trigger(self::EVENT_BEFORE_APPLY_VOLUME_DELETE, new VolumeEvent([
                'volume' => $volume,
            ]));
        }

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            $volume->beforeApplyDelete();

            // Delete the assets
            $assets = Asset::find()
                ->status(null)
                ->volumeId($volumeRecord->id)
                ->all();
            $elementsService = Craft::$app->getElements();

            foreach ($assets as $asset) {
                $asset->deletedWithVolume = true;
                $asset->keepFileOnDelete = true;
                $elementsService->deleteElement($asset);
            }

            // Delete the field layout
            if ($volumeRecord->fieldLayoutId) {
                Craft::$app->getFields()->deleteLayoutById($volumeRecord->fieldLayoutId);
            }

            // Delete the volume
            $db->createCommand()
                ->softDelete(Table::VOLUMES, ['id' => $volumeRecord->id])
                ->execute();

            $volume->afterDelete();

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        // Clear caches
        $this->_volumes = null;

        // Fire an 'afterDeleteVolume' event
        if ($this->hasEventHandlers(self::EVENT_AFTER_DELETE_VOLUME)) {
            $this->trigger(self::EVENT_AFTER_DELETE_VOLUME, new VolumeEvent([
                'volume' => $volume,
            ]));
        }

        // Invalidate asset caches
        Craft::$app->getElements()->invalidateCachesForElementType(Asset::class);
    }

    /**
     * Prune a deleted field from volume layouts.
     *
     * @param FieldEvent $event
     */
    public function pruneDeletedField(FieldEvent $event): void
    {
        $field = $event->field;
        $fieldUid = $field->uid;

        $projectConfig = Craft::$app->getProjectConfig();
        $volumes = $projectConfig->get(ProjectConfig::PATH_VOLUMES);

        // Engage stealth mode
        $projectConfig->muteEvents = true;

        // Loop through the volumes and prune the UID from field layouts.
        if (is_array($volumes)) {
            foreach ($volumes as $volumeUid => $volume) {
                if (!empty($volume['fieldLayouts'])) {
                    foreach ($volume['fieldLayouts'] as $layoutUid => $layout) {
                        if (!empty($layout['tabs'])) {
                            foreach ($layout['tabs'] as $tabUid => $tab) {
                                $projectConfig->remove(ProjectConfig::PATH_VOLUMES . '.' . $volumeUid . '.fieldLayouts.' . $layoutUid . '.tabs.' . $tabUid . '.fields.' . $fieldUid, 'Prune deleted field');
                            }
                        }
                    }
                }
            }
        }

        // Nuke all the layout fields from the DB
        Db::delete(Table::FIELDLAYOUTFIELDS, [
            'fieldId' => $field->id,
        ]);

        // Allow events again
        $projectConfig->muteEvents = false;
    }

    /**
     * Returns a DbCommand object prepped for retrieving volumes.
     *
     * @return Query
     */
    private function _createVolumeQuery(): Query
    {
        return (new Query())
            ->select([
                'id',
                'name',
                'handle',
                'titleTranslationMethod',
                'titleTranslationKeyFormat',
                'sortOrder',
                'fieldLayoutId',
                'filesystem',
                'uid',
            ])
            ->from([Table::VOLUMES])
            ->where(['dateDeleted' => null])
            ->orderBy(['sortOrder' => SORT_ASC]);
    }

    /**
     * Gets a volume's record by uid.
     *
     * @param string $uid
     * @param bool $withTrashed Whether to include trashed volumes in search
     * @return AssetVolumeRecord
     */
    private function _getVolumeRecord(string $uid, bool $withTrashed = false): AssetVolumeRecord
    {
        $query = $withTrashed ? AssetVolumeRecord::findWithTrashed() : AssetVolumeRecord::find();
        $query->andWhere(['uid' => $uid]);
        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return $query->one() ?? new AssetVolumeRecord();
    }
}
