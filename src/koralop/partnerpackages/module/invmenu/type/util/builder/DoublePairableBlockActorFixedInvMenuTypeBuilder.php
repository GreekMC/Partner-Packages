<?php

declare(strict_types=1);

namespace koralop\partnerpackages\module\invmenu\type\util\builder;

use koralop\partnerpackages\module\invmenu\type\DoublePairableBlockActorFixedInvMenuType;
use koralop\partnerpackages\module\invmenu\type\graphic\network\BlockInvMenuGraphicNetworkTranslator;
use LogicException;

final class DoublePairableBlockActorFixedInvMenuTypeBuilder implements InvMenuTypeBuilder{
	use BlockInvMenuTypeBuilderTrait;
	use FixedInvMenuTypeBuilderTrait;
	use GraphicNetworkTranslatableInvMenuTypeBuilderTrait;
	use AnimationDurationInvMenuTypeBuilderTrait;

	private ?string $block_actor_id = null;

	public function __construct(){
		$this->addGraphicNetworkTranslator(BlockInvMenuGraphicNetworkTranslator::instance());
	}

	public function setBlockActorId(string $block_actor_id) : self{
		$this->block_actor_id = $block_actor_id;
		return $this;
	}

	private function getBlockActorId() : string{
		return $this->block_actor_id ?? throw new LogicException("No block actor ID was specified");
	}

	public function build() : DoublePairableBlockActorFixedInvMenuType{
		return new DoublePairableBlockActorFixedInvMenuType($this->getBlock(), $this->getSize(), $this->getBlockActorId(), $this->getGraphicNetworkTranslator(), $this->getAnimationDuration());
	}
}