<?php

declare(strict_types=1);

namespace koralop\partnerpackages\module\invmenu;

use Closure;
use koralop\partnerpackages\module\invmenu\inventory\SharedInvMenuSynchronizer;
use koralop\partnerpackages\module\invmenu\session\InvMenuInfo;
use koralop\partnerpackages\module\invmenu\session\network\PlayerNetwork;
use koralop\partnerpackages\module\invmenu\transaction\DeterministicInvMenuTransaction;
use koralop\partnerpackages\module\invmenu\transaction\InvMenuTransaction;
use koralop\partnerpackages\module\invmenu\transaction\InvMenuTransactionResult;
use koralop\partnerpackages\module\invmenu\transaction\SimpleInvMenuTransaction;
use koralop\partnerpackages\module\invmenu\type\InvMenuType;
use koralop\partnerpackages\module\invmenu\type\InvMenuTypeIds;
use LogicException;
use pocketmine\inventory\Inventory;
use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\inventory\transaction\InventoryTransaction;
use pocketmine\item\Item;
use pocketmine\player\Player;

class InvMenu implements InvMenuTypeIds{

	/**
	 * @param string $identifier
	 * @param mixed ...$args
	 * @return InvMenu
	 */
	public static function create(string $identifier, ...$args) : InvMenu{
		return new InvMenu(InvMenuHandler::getTypeRegistry()->get($identifier), ...$args);
	}

	/**
	 * @param (Closure(\koralop\partnerpackages\module\invmenu\transaction\\partnerpackages\module\invmenu\transaction\DeterministicInvMenuTransaction) : void)|null $listener
	 * @return Closure(\koralop\partnerpackages\module\invmenu\transaction\\partnerpackages\module\invmenu\transaction\\koralop\partnerpackages\module\invmenu\transaction\InvMenuTransaction) : \koralop\partnerpackages\module\invmenu\transaction\\partnerpackages\module\invmenu\transaction\\koralop\partnerpackages\module\invmenu\transaction\InvMenuTransactionResult
	 */
	public static function readonly(?Closure $listener = null) : Closure{
		return static function(InvMenuTransaction $transaction) use($listener) : InvMenuTransactionResult{
			$result = $transaction->discard();
			if($listener !== null){
				$listener(new DeterministicInvMenuTransaction($transaction, $result));
			}
			return $result;
		};
	}

	readonly public InvMenuType $type;
	protected ?string $name = null;
	protected ?Closure $listener = null;
	protected ?Closure $inventory_close_listener = null;
	protected Inventory $inventory;
	protected ?SharedInvMenuSynchronizer $synchronizer = null;

	public function __construct(InvMenuType $type, ?Inventory $custom_inventory = null){
		if(!InvMenuHandler::isRegistered()){
			throw new LogicException("Tried creating menu before calling " . InvMenuHandler::class . "::register()");
		}
		$this->type = $type;
		$this->inventory = $this->type->createInventory();
		$this->setInventory($custom_inventory);
	}

	public function __destruct(){
		$this->setInventory(null);
	}

	/**
	 * @return \koralop\partnerpackages\module\invmenu\type\InvMenuType
	 *@deprecated Access {@see InvMenu::$type} directly
	 */
	public function getType() : InvMenuType{
		return $this->type;
	}

	public function getName() : ?string{
		return $this->name;
	}

	public function setName(?string $name) : self{
		$this->name = $name;
		return $this;
	}

	/**
	 * @return (Closure(\koralop\partnerpackages\module\invmenu\transaction\\partnerpackages\module\invmenu\transaction\\koralop\partnerpackages\module\invmenu\transaction\InvMenuTransaction) : \partnerpackages\module\invmenu\transaction\\koralop\partnerpackages\module\invmenu\transaction\InvMenuTransactionResult)|null
	 */
	public function getListener() : ?Closure{
		return $this->listener;
	}

	/**
	 * @param (Closure(InvMenuTransaction) : \partnerpackages\module\invmenu\transaction\\koralop\partnerpackages\module\invmenu\transaction\InvMenuTransactionResult)|null $listener
	 * @return self
	 */
	public function setListener(?Closure $listener) : self{
		$this->listener = $listener;
		return $this;
	}

	/**
	 * @return (Closure(Player, Inventory) : void)|null
	 */
	public function getInventoryCloseListener() : ?Closure{
		return $this->inventory_close_listener;
	}

	/**
	 * @param (Closure(Player, Inventory) : void)|null $listener
	 * @return self
	 */
	public function setInventoryCloseListener(?Closure $listener) : self{
		$this->inventory_close_listener = $listener;
		return $this;
	}

	public function getInventory() : Inventory{
		return $this->inventory;
	}

	public function setInventory(?Inventory $custom_inventory) : void{
		if($this->synchronizer !== null){
			$this->synchronizer->destroy();
			$this->synchronizer = null;
		}

		if($custom_inventory !== null){
			$this->synchronizer = new SharedInvMenuSynchronizer($this, $custom_inventory);
		}
	}

	/**
	 * @param Player $player
	 * @param string|null $name
	 * @param (Closure(bool) : void)|null $callback
	 */
	final public function send(Player $player, ?string $name = null, ?Closure $callback = null) : void{
		$player->removeCurrentWindow();

		$session = InvMenuHandler::getPlayerManager()->get($player);
		$network = $session->network;

		// Avoid players from spamming InvMenu::send() and other similar
		// requests and filling up queued tasks in memory.
		// It would be better if this check were implemented by plugins,
		// however I suppose it is more convenient if done within InvMenu...
		if($network->getPending() >= 8){
			$network->dropPending();
		}else{
			$network->dropPendingOfType(PlayerNetwork::DELAY_TYPE_OPERATION);
		}

		$network->waitUntil(PlayerNetwork::DELAY_TYPE_OPERATION, 50 * 8, function(bool $success) use($player, $session, $name, $callback) : bool{
			if(!$success){
				if($callback !== null){
					$callback(false);
				}
				return false;
			}

			$graphic = $this->type->createGraphic($this, $player);
			if($graphic !== null){
				$session->setCurrentMenu(new InvMenuInfo($this, $graphic, $name), static function(bool $success) use($callback) : void{
					if($callback !== null){
						$callback($success);
					}
				});
			}else{
				if($callback !== null){
					$callback(false);
				}
			}
			return false;
		});
	}

	/**
	 * @internal use InvMenu::send() instead.
	 *
	 * @param Player $player
	 * @return bool
	 */
	public function sendInventory(Player $player) : bool{
		return $player->setCurrentWindow($this->getInventory());
	}

	public function handleInventoryTransaction(Player $player, Item $out, Item $in, SlotChangeAction $action, InventoryTransaction $transaction) : InvMenuTransactionResult{
		$inv_menu_txn = new SimpleInvMenuTransaction($player, $out, $in, $action, $transaction);
		return $this->listener !== null ? ($this->listener)($inv_menu_txn) : $inv_menu_txn->continue();
	}

	public function onClose(Player $player) : void{
		if($this->inventory_close_listener !== null){
			($this->inventory_close_listener)($player, $this->getInventory());
		}

		InvMenuHandler::getPlayerManager()->get($player)->removeCurrentMenu();
	}
}
