<?php

namespace Shop;

use pocketmine\plugin\PluginBase;
use pocketmine\player\Player;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\inventory\Inventory;
use pocketmine\item\StringToItemParser;
use pocketmine\item\Item;
use pocketmine\utils\Config;

use dktapps\pmforms\MenuForm;
use dktapps\pmforms\MenuOption;
use dktapps\pmforms\FormIcon;
use dktapps\pmforms\CustomForm;
use dktapps\pmforms\CustomFormResponse;
use dktapps\pmforms\element\Slider;
use dktapps\pmforms\element\Input;
use dktapps\pmforms\element\Label;
use dktapps\pmforms\element\Toggle;

use cooldogedev\BedrockEconomy\api\BedrockEconomyAPI;
use onebone\economyapi\EconomyAPI;

class Main extends PluginBase {
    private array $categories = [];
    private array $items = [];
    private array $messages = [];

    public function onEnable(): void {
        $this->saveDefaultConfig();
        if (!file_exists($this->getDataFolder() . "shop.yml")) {
            $this->saveResource("shop.yml"); 
        }
        $this->loadMarketData();
        $this->loadMessages();
    }

    private function loadMessages(): void {
        $this->messages = $this->getConfig()->get("messages", []);
    }

    private function loadMarketData(): void {
        $shopConfig = yaml_parse_file($this->getDataFolder() . "shop.yml");
        $this->categories = $shopConfig["categories"] ?? [];
        $this->items = $shopConfig["items"] ?? [];
    }

    private function isUsingBedrockEconomy(): bool {
        return $this->getConfig()->getNested("economy.use_bedrockeconomy", true);
    }

    private function isUsingEconomyAPI(): bool {
        return $this->getConfig()->getNested("economy.use_economyapi", false);
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if($command->getName() === "shop") {
            if(!$sender instanceof Player) {
                $sender->sendMessage("§cYou can only use this command in the game");
                return true;
            }
            $this->openMainMenu($sender);
            return true;
        }
        return false;
    }

    public function openMainMenu(Player $player): void {
        $buttons = [];

        $buttons[] = new MenuOption($this->messages["search_option"], new FormIcon($this->messages["search_icon"], FormIcon::IMAGE_TYPE_PATH));

        foreach($this->categories as $categoryId => $category) {
            $buttons[] = new MenuOption(
                $category["name"],
                new FormIcon($category["texture"], FormIcon::IMAGE_TYPE_PATH)
            );
        }

        $form = new MenuForm(
            $this->messages["main_menu_title"],
            $this->messages["main_menu_subtitle"],
            $buttons,
            function(Player $player, int $selected): void {
                $categoryIds = array_keys($this->categories);
                if($selected === 0) {
                    $this->openSearchForm($player);
                } elseif(isset($categoryIds[$selected - 1])) {
                    $this->openCategoryMenu($player, $categoryIds[$selected - 1]);
                }
            }
        );

        $player->sendForm($form);
    }

    public function openCategoryMenu(Player $player, string $categoryId): void {
        if(!isset($this->items[$categoryId]) || empty($this->items[$categoryId])) {
            $player->sendMessage($this->messages["no_items_message"]);
            $this->openMainMenu($player);
            return;
        }

        $buttons = [];

        $buttons[] = new MenuOption($this->messages["back_option"], new FormIcon($this->messages["back_icon"], FormIcon::IMAGE_TYPE_PATH));

        foreach($this->items[$categoryId] as $item) {
            $buttonText = "§6" . $item["name"] . "\n§7" . $this->messages["item_price"] . ": §e" . $item["price"] . " " . $this->messages["currency"];
            if(isset($item["description"])) {
                $buttonText .= "\n§7" . $item["description"];
            }

            if(isset($item["texture"])) {
                $buttons[] = new MenuOption(
                    $buttonText,
                    new FormIcon($item["texture"], FormIcon::IMAGE_TYPE_PATH)
                );
            } else {
                $buttons[] = new MenuOption($buttonText);
            }
        }

        $form = new MenuForm(
            sprintf($this->messages["category_menu_title"], $this->categories[$categoryId]["name"]),
            $this->messages["category_menu_subtitle"],
            $buttons,
            function(Player $player, int $selected) use ($categoryId): void {
                if ($selected === 0) {
                    
                    $this->openMainMenu($player);
                } else {
                    if(isset($this->items[$categoryId][$selected - 1])) {
                        $this->openPurchaseForm($player, $this->items[$categoryId][$selected - 1], $categoryId);
                    }
                }
            }
        );

        $player->sendForm($form);
    }

    public function openSearchForm(Player $player): void {
        $form = new CustomForm(
            $this->messages["search_option"],
            [
                new Label("info", $this->messages["search_option"]),
                new Input("query", "Search", "")
            ],
            function(Player $player, CustomFormResponse $response): void {
                $query = $response->getString("query");
                $this->showSearchResults($player, $query);
            }
        );

        $player->sendForm($form);
    }

    public function showSearchResults(Player $player, string $query): void {
        $query = strtolower($query);
        $results = [];

        foreach($this->categories as $categoryId => $category) {
            if(strpos(strtolower($category["name"]), $query) !== false) {
                $results[] = [
                    "type" => "category",
                    "id" => $categoryId,
                    "name" => $category["name"],
                    "texture" => $category["texture"]
                ];
            }
        }

        foreach($this->items as $categoryId => $items) {
            foreach($items as $item) {
                if(strpos(strtolower($item["name"]), $query) !== false) {
                    $results[] = [
                        "type" => "item",
                        "category" => $categoryId,
                        "itemIndex" => array_search($item, $items), 
                        "name" => $item["name"],
                        "texture" => $item["texture"] ?? ""
                    ];
                }
            }
        }

        if(empty($results)) {
            $player->sendMessage($this->messages["no_results_message"]);
            $this->openMainMenu($player);
            return;
        }

        $buttons = [];
        foreach($results as $result) {
            $buttons[] = new MenuOption(
                $result["name"],
                $result["texture"] ? new FormIcon($result["texture"], FormIcon::IMAGE_TYPE_PATH) : null
            );
        }

        $form = new MenuForm(
            $this->messages["search_results_title"],
            $this->messages["search_results_subtitle"],
            $buttons,
            function(Player $player, int $selected) use ($results): void {
                $result = $results[$selected];
                if($result["type"] === "category") {
                    $this->openCategoryMenu($player, $result["id"]);
                } else {
                    $this->openPurchaseForm($player, $this->items[$result["category"]][$result["itemIndex"]], $result["category"]);
                }
            }
        );

        $player->sendForm($form);
    }

    public function openPurchaseForm(Player $player, array $itemData, string $categoryId): void {
        $count = $itemData["count"] ?? 1;
        $description = isset($itemData["description"]) ? "\n§7" . $itemData["description"] : "";

        $form = new CustomForm(
            sprintf($this->messages["purchase_form_title"], $itemData["name"]),
            [
                new Label("info", sprintf($this->messages["purchase_form_info"], $itemData["name"], $itemData["price"], $this->messages["currency"], $description)),
                new Toggle("stack", $this->messages["purchase_form_stack_toggle"]),
                new Slider("amount", $this->messages["purchase_form_amount"], 1, 64, 1, $count)
            ],
            function(Player $player, CustomFormResponse $response) use ($itemData, $categoryId): void {
                $amount = (int)$response->getFloat("amount");
                $isStack = $response->getBool("stack");
                if ($isStack) {
                    $amount *= 64;
                }
                $totalPrice = $amount * $itemData["price"];
                $this->openConfirmPurchaseForm($player, $itemData, $amount, $totalPrice, $categoryId);
            }
        );

        $player->sendForm($form);
    }

    public function openConfirmPurchaseForm(Player $player, array $itemData, int $amount, int $totalPrice, string $categoryId): void {
        $form = new MenuForm(
            $this->messages["confirm_purchase_title"],
            sprintf($this->messages["confirm_purchase_info"], $itemData["name"], $amount, $totalPrice, $this->messages["currency"]),
            [
                new MenuOption($this->messages["confirm_purchase_yes"]),
                new MenuOption($this->messages["confirm_purchase_no"])
            ],
            function(Player $player, int $selected) use ($itemData, $amount, $totalPrice, $categoryId): void {
                if($selected === 0) {
            
                    if (!$this->hasInventorySpace($player, $itemData["item"], $amount)) {
                        $player->sendMessage($this->messages["inventory_full_message"]);
                        return;
                    }
                
                    $this->processPurchase($player, $totalPrice, function(bool $success) use ($player, $itemData, $amount, $categoryId) {
                        if ($success) {
                            $item = $this->createItem($itemData["item"], $amount);
                            if($item !== null) {
                                $player->getInventory()->addItem($item);
                                $player->sendMessage(sprintf($this->messages["purchase_success"], $amount, $itemData["name"]));
                            }
                        } else {
                            $player->sendMessage($this->messages["not_enough_money"]);
                        }
                        $this->openCategoryMenu($player, $categoryId);
                    });
                } else {
                    $this->openCategoryMenu($player, $categoryId);
                }
            }
        );

        $player->sendForm($form);
    }

    private function processPurchase(Player $player, int $totalPrice, callable $callback): void {
        if ($this->isUsingBedrockEconomy()) {
            BedrockEconomyAPI::getInstance()->subtractFromPlayerBalance($player->getName(), $totalPrice, function(bool $success) use ($callback) {
                $callback($success);
            });
        } elseif ($this->isUsingEconomyAPI()) {
            $economyAPI = EconomyAPI::getInstance();
            $balance = $economyAPI->myMoney($player);
            if ($balance >= $totalPrice) {
                $economyAPI->reduceMoney($player, $totalPrice);
                $callback(true);
            } else {
                $callback(false);
            }
        } else {
            $callback(false);
        }
    }

    private function createItem(string $itemString, int $count): ?Item {
        $item = StringToItemParser::getInstance()->parse($itemString);
        if($item !== null) {
            $item->setCount($count);
            return $item;
        }
        return null;
    }

    private function hasInventorySpace(Player $player, string $itemString, int $amount): bool {
        $inventory = $player->getInventory();
        $item = StringToItemParser::getInstance()->parse($itemString);
        if ($item === null) return false;
        
        $item->setCount($amount);
        $requiredSlots = ceil($amount / $item->getMaxStackSize());
        $freeSlots = 0;

        foreach ($inventory->getContents() as $slotItem) {
            if ($slotItem->isNull()) {
                $freeSlots++;
            } elseif ($slotItem->equals($item, true, false)) {
                $freeSlots += (int) floor(($slotItem->getMaxStackSize() - $slotItem->getCount()) / $item->getMaxStackSize());
            }
            if ($freeSlots >= $requiredSlots) {
                return true;
            }
        }

        $remainingSlots = $inventory->getSize() - count($inventory->getContents());
        $freeSlots += $remainingSlots;

        return $freeSlots >= $requiredSlots;
    }
}