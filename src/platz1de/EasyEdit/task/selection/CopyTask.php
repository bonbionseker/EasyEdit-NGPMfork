<?php

namespace platz1de\EasyEdit\task\selection;

use Closure;
use platz1de\EasyEdit\Messages;
use platz1de\EasyEdit\pattern\Pattern;
use platz1de\EasyEdit\selection\BlockListSelection;
use platz1de\EasyEdit\selection\ClipBoardManager;
use platz1de\EasyEdit\selection\DynamicBlockListSelection;
use platz1de\EasyEdit\selection\Selection;
use platz1de\EasyEdit\task\EditTask;
use platz1de\EasyEdit\task\EditTaskResult;
use platz1de\EasyEdit\task\queued\QueuedEditTask;
use platz1de\EasyEdit\utils\AdditionalDataManager;
use platz1de\EasyEdit\utils\SafeSubChunkIteratorManager;
use platz1de\EasyEdit\utils\TaskCache;
use platz1de\EasyEdit\utils\TileUtils;
use platz1de\EasyEdit\worker\WorkerAdapter;
use pocketmine\world\World;
use pocketmine\world\Position;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;

class CopyTask extends EditTask
{
	/**
	 * @param Selection    $selection
	 * @param Position     $place
	 * @param Closure|null $finish
	 */
	public static function queue(Selection $selection, Position $place, ?Closure $finish = null): void
	{
		if ($finish === null) {
			$finish = static function (EditTaskResult $result): void {
				/** @var DynamicBlockListSelection $copied */
				$copied = $result->getUndo();
				ClipBoardManager::setForPlayer($copied->getPlayer(), $copied);
			};
		}
		WorkerAdapter::queue(new QueuedEditTask($selection, new Pattern([], []), $place, self::class, new AdditionalDataManager(), $selection->getPos1()->multiply(-1), $finish));
	}

	/**
	 * @return string
	 */
	public function getTaskName(): string
	{
		return "copy";
	}

	/**
	 * @param SafeSubChunkIteratorManager $iterator
	 * @param CompoundTag[]               $tiles
	 * @param Selection                   $selection
	 * @param Pattern                     $pattern
	 * @param Vector3                     $place
	 * @param BlockListSelection          $toUndo
	 * @param SafeSubChunkIteratorManager $origin
	 * @param int                         $changed
	 * @param AdditionalDataManager       $data
	 */
	public function execute(SafeSubChunkIteratorManager $iterator, array &$tiles, Selection $selection, Pattern $pattern, Vector3 $place, BlockListSelection $toUndo, SafeSubChunkIteratorManager $origin, int &$changed, AdditionalDataManager $data): void
	{
		$full = TaskCache::getFullSelection();
		$selection->useOnBlocks($place, function (int $x, int $y, int $z) use ($iterator, &$tiles, $toUndo, &$changed, $full): void {
			$iterator->moveTo($x, $y, $z);
			$toUndo->addBlock($x - $full->getPos1()->getFloorX(), $y - $full->getPos1()->getFloorY(), $z - $full->getPos1()->getFloorZ(), $iterator->getCurrent()->getBlockId($x & 0x0f, $y & 0x0f, $z & 0x0f), $iterator->getCurrent()->getBlockData($x & 0x0f, $y & 0x0f, $z & 0x0f));
			$changed++;

			if (isset($tiles[World::blockHash($x, $y, $z)])) {
				$toUndo->addTile(TileUtils::offsetCompound($tiles[World::blockHash($x, $y, $z)], $full->getPos1()->multiply(-1)));
			}
		});
	}

	/**
	 * @param Selection             $selection
	 * @param Vector3               $place
	 * @param string                $level
	 * @param AdditionalDataManager $data
	 * @return DynamicBlockListSelection
	 */
	public function getUndoBlockList(Selection $selection, Vector3 $place, string $level, AdditionalDataManager $data): BlockListSelection
	{
		return new DynamicBlockListSelection($selection->getPlayer(), $place, $selection->getCubicStart(), $selection->getCubicEnd());
	}

	/**
	 * @param Selection             $selection
	 * @param float                 $time
	 * @param string                $changed
	 * @param AdditionalDataManager $data
	 */
	public function notifyUser(Selection $selection, float $time, string $changed, AdditionalDataManager $data): void
	{
		Messages::send($selection->getPlayer(), "blocks-copied", ["{time}" => (string) $time, "{changed}" => $changed]);
	}
}