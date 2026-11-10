<?php
// Causa 1: JigsawStructureData + VoxelShapes antes do StartGame (>=2225).
// Causa 2: PlayerAuthInput 26.60 — InputData sem bool extra + Optionals sem bool duplicado (>=2225).
$phar = $argv[1] ?? 'Essential.phar';

function patch(string &$c, string $find, string $replace, string $label) : void{
	$n = substr_count($c, $find);
	if($n !== 1){ fwrite(STDERR, "FAIL [$label]: $n vez(es), esperado 1\n"); exit(1); }
	$c = str_replace($find, $replace, $c);
	echo "  OK  $label\n";
}
function lint(string $code, string $label) : void{
	$tmp = tempnam(sys_get_temp_dir(), 'lint').'.php';
	file_put_contents($tmp, $code);
	exec('php -l '.escapeshellarg($tmp).' 2>&1', $o, $r);
	unlink($tmp);
	if($r !== 0){ fwrite(STDERR, "LINT FAIL [$label]:\n".implode("\n",$o)."\n"); exit(1); }
}

// ===== Causa 1: PreSpawnPacketHandler =====
$prePath = 'src/network/mcpe/handler/PreSpawnPacketHandler.php';
$pre = file_get_contents("phar://$phar/$prePath");

$jig = "\t\t\t\$levelSettings->experiments = new Experiments([], false);\n\n\t\t\t\$this->session->sendDataPacket(StartGamePacket::create(";
$jigNew = "\t\t\t\$levelSettings->experiments = new Experiments([], false);\n\n"
	. "\t\t\tif(\$protocolId >= ProtocolInfo::PROTOCOL_1_26_62){ //1.26.60 exige esses 2 antes do StartGame, senão 'Dados de estrutura ausentes'\n"
	. "\t\t\t\t\$this->session->sendDataPacket(\\pocketmine\\network\\mcpe\\protocol\\JigsawStructureDataPacket::create(new CacheableNbt(\n"
	. "\t\t\t\t\tCompoundTag::create()\n"
	. "\t\t\t\t\t\t->setTag(\"processors\", new \\pocketmine\\nbt\\tag\\ListTag([], \\pocketmine\\nbt\\NBT::TAG_Compound))\n"
	. "\t\t\t\t\t\t->setTag(\"template_pools\", new \\pocketmine\\nbt\\tag\\ListTag([], \\pocketmine\\nbt\\NBT::TAG_Compound))\n"
	. "\t\t\t\t\t\t->setTag(\"jigsaws\", new \\pocketmine\\nbt\\tag\\ListTag([], \\pocketmine\\nbt\\NBT::TAG_Compound))\n"
	. "\t\t\t\t\t\t->setTag(\"structure_sets\", new \\pocketmine\\nbt\\tag\\ListTag([], \\pocketmine\\nbt\\NBT::TAG_Compound))\n"
	. "\t\t\t\t)));\n"
	. "\t\t\t\t\$this->session->sendDataPacket(\\pocketmine\\network\\mcpe\\protocol\\VoxelShapesPacket::create([], []));\n"
	. "\t\t\t}\n\n"
	. "\t\t\t\$this->session->sendDataPacket(StartGamePacket::create(";
patch($pre, $jig, $jigNew, "Causa1: Jigsaw+VoxelShapes antes do StartGame");
lint($pre, "PreSpawnPacketHandler");

// ===== Causa 2: PlayerAuthInputPacket =====
$paiPath = 'src/network/mcpe/protocol/PlayerAuthInputPacket.php';
$pai = file_get_contents("phar://$phar/$paiPath");

// 2a: InputData — sem o bool extra no 2225
patch($pai,
	"\t\t\t\$this->inputFlags = 0;\n\t\t\tif(\$in->getBool()){\n\t\t\t\tfor(\$i = 0, \$count = \$in->getUnsignedVarInt(); \$i < \$count; ++\$i){",
	"\t\t\t\$this->inputFlags = 0;\n\t\t\tif(\$in->getProtocolId() >= ProtocolInfo::PROTOCOL_1_26_62 || \$in->getBool()){\n\t\t\t\tfor(\$i = 0, \$count = \$in->getUnsignedVarInt(); \$i < \$count; ++\$i){",
	"Causa2a: InputData sem bool extra");

// 2b: Optionals — curto-circuito pra pular o bool-guard externo no 2225 (readOptional ja le o bool)
patch($pai,
	"\t\t\tif(\$in->getBool()){\n\t\t\t\t\$this->itemInteractionData = \$in->readOptional(fn() => ItemInteractionData::read(\$in));",
	"\t\t\tif(\$in->getProtocolId() >= ProtocolInfo::PROTOCOL_1_26_62 || \$in->getBool()){\n\t\t\t\t\$this->itemInteractionData = \$in->readOptional(fn() => ItemInteractionData::read(\$in));",
	"Causa2b: optional itemInteractionData");
patch($pai,
	"\t\t\tif(\$in->getBool()){\n\t\t\t\t\$this->itemStackRequest = \$in->readOptional(fn() => ItemStackRequest::read(\$in));",
	"\t\t\tif(\$in->getProtocolId() >= ProtocolInfo::PROTOCOL_1_26_62 || \$in->getBool()){\n\t\t\t\t\$this->itemStackRequest = \$in->readOptional(fn() => ItemStackRequest::read(\$in));",
	"Causa2b: optional itemStackRequest");
patch($pai,
	"\t\t\tif(\$in->getBool()){\n\t\t\t\t\$this->blockActions = \$in->readOptional(function() use (\$in) : array{",
	"\t\t\tif(\$in->getProtocolId() >= ProtocolInfo::PROTOCOL_1_26_62 || \$in->getBool()){\n\t\t\t\t\$this->blockActions = \$in->readOptional(function() use (\$in) : array{",
	"Causa2b: optional blockActions");
patch($pai,
	"\t\t\t\$vehicleRotation = \$in->getBool() ? \$in->readOptional(fn() => \$in->getVector2()) : null;",
	"\t\t\t\$vehicleRotation = (\$in->getProtocolId() >= ProtocolInfo::PROTOCOL_1_26_62 || \$in->getBool()) ? \$in->readOptional(fn() => \$in->getVector2()) : null;",
	"Causa2b: optional vehicleRotation");
patch($pai,
	"\t\t\t\$vehicleActorUniqueId = \$in->getBool() ? \$in->readOptional(fn() => \$in->getActorUniqueId()) : null;",
	"\t\t\t\$vehicleActorUniqueId = (\$in->getProtocolId() >= ProtocolInfo::PROTOCOL_1_26_62 || \$in->getBool()) ? \$in->readOptional(fn() => \$in->getActorUniqueId()) : null;",
	"Causa2b: optional vehicleActorUniqueId");
lint($pai, "PlayerAuthInputPacket");

$p = new Phar($phar);
$p->startBuffering();
$p[$prePath] = $pre;
$p[$paiPath] = $pai;
$p->stopBuffering();
echo "\n=== phar atualizado (Causa 1 + Causa 2) ===\n";
