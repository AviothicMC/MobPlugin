<?php

/*
 *   __  __       _     _____  _             _
 *  |  \/  |     | |   |  __ \| |           (_)
 *  | \  / | ___ | |__ | |__) | |_   _  __ _ _ _ __
 *  | |\/| |/ _ \| '_ \|  ___/| | | | |/ _` | | '_ \
 *  | |  | | (_) | |_) | |    | | |_| | (_| | | | | |
 *  |_|  |_|\___/|_.__/|_|    |_|\__,_|\__, |_|_| |_|
 *                                      __/ |
 *                                     |___/
 *
 * A PocketMine-MP plugin that implements mobs AI.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 *
 * @author IvanCraft623
 */

declare(strict_types=1);

namespace IvanCraft623\MobPlugin\entity;

use pocketmine\entity\Attribute;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\player\ChunkSelector;
use pocketmine\world\ChunkListener;
use pocketmine\world\format\Chunk;

abstract class PathfinderMob extends Mob implements ChunkListener {
	//TODO!

	protected ChunkSelector $chunkSelector;

	/**
	 * @var bool[] chunkHash => isUsed
	 * @phpstan-var array<int, true>
	 */
	protected array $usedChunks = [];

	protected int $viewDistance;

	protected int $viewAreaCenterPoint = -1; //ChunkPosHash

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);

		$this->chunkSelector = new ChunkSelector();
		$followRange = $this->getAttributeMap()->get(Attribute::FOLLOW_RANGE)?->getValue() ?? throw new AssumptionFailedError("Follow range attribute is null");
		$this->viewDistance = $followRange >> Chunk::COORD_BIT_SIZE;
	}

	protected function orderChunks() : void{
		if(!$this->isFlaggedForDespawn()){
			return;
		}

		$chunkX = $this->location->getFloorX() >> Chunk::COORD_BIT_SIZE;
		$chunkZ = $this->location->getFloorZ() >> Chunk::COORD_BIT_SIZE;

		$unloadChunks = $this->usedChunks;

		foreach($this->chunkSelector->selectChunks(
			$this->viewDistance,
			$chunkX,
			$chunkZ
		) as $hash){
			if(!isset($this->usedChunks[$hash])){
				World::getXZ($index, $X, $Z);
				$this->getWorld()->registerChunkListener($this, $X, $Z);
				$this->usedChunks[$hash] = true;
			}
			unset($unloadChunks[$hash]);
		}

		foreach($unloadChunks as $index => $bool){
			World::getXZ($index, $X, $Z);
			$this->getWorld()->unregisterChunkListener($this, $X, $Z);
		}

		$this->viewAreaCenterPoint = World::chunkHash($chunkX, $chunkZ);
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);

		$currentChunk = World::chunkHash($this->location->getFloorX() >> Chunk::COORD_BIT_SIZE, $this->location->getFloorZ() >> Chunk::COORD_BIT_SIZE);
		$followRange = $this->getAttributeMap()->get(Attribute::FOLLOW_RANGE)?->getValue() ?? throw new AssumptionFailedError("Follow range attribute is null");
		$currentViewDistance = $followRange >> Chunk::COORD_BIT_SIZE;

		if ($this->viewAreaCenterPoint === -1 ||
			$currentChunk !== $this->viewAreaCenterPoint ||
			$currentViewDistance !== $this->viewDistance
		) {
			$this->orderChunks();
			$this->viewDistance = $currentViewDistance;

			$hasUpdate = true;
		}

		return $hasUpdate;
	}

	/**
	 * Returns whether the mob is using the chunk with the given coordinates, irrespective of whether the chunk has
	 * been sent yet.
	 */
	public function isUsingChunk(int $chunkX, int $chunkZ) : bool{
		return $this->usedChunks[World::chunkHash($chunkX, $chunkZ)] ?? false;
	}

	/**
	 * @return bool[] chunkHash => isUsing
	 * @phpstan-return array<int, true>
	 */
	public function getUsedChunks() : array{
		return $this->usedChunks;
	}

	public function getViewDistance() : int{
		return $this->viewDistance;
	}

	public function isPathFinding() : bool{
		return !$this->navigation->isDone();
	}

	public function onBlockChanged(Vector3 $position) : void{
		// It would be great to be able to compare block collisions to save execution time but
		// with the current pocketmine implementation there is no an easy way to know which block was before
		if ($this->navigation->shouldRecomputePath($position)) {
			$this->navigation->recomputePath();
		}
	}
}
