<?php
// Round 5: ItemStackResponse no 1.26.40 tinha "optional duplo" manual (2 bools) em volta
// da lista de containers. No 26.60/2225 e Optional simples (gophertunnel ItemStackResponse.Marshal).
// Sintoma: crafting/mover item derrubava o cliente ("Block", PacketViolationWarning id 148).
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
$f = 'src/network/mcpe/protocol/types/inventory/stackresponse/ItemStackResponse.php';
$c = file_get_contents("phar://$phar/$f");
patch($c,
	"\t\t\tif(\$in->getBool() && \$in->getBool()){",
	"\t\t\tif(\$in->getBool() && (\$in->getProtocolId() >= ProtocolInfo::PROTOCOL_1_26_62 || \$in->getBool())){",
	"ItemStackResponse::read: bool unico no 2225");
patch($c,
	"\t\t\tif(\$hasContainers){\n\t\t\t\t\$out->putBool(true);\n",
	"\t\t\tif(\$hasContainers){\n\t\t\t\tif(\$out->getProtocolId() < ProtocolInfo::PROTOCOL_1_26_62){\n\t\t\t\t\t\$out->putBool(true);\n\t\t\t\t}\n",
	"ItemStackResponse::write: bool unico no 2225");
lint($c, $f);
if(($argv[2] ?? '') === '--dry'){ echo "\n=== dry-run OK ===\n"; exit(0); }
$p = new Phar($phar);
$p->startBuffering();
$p[$f] = $c;
$p->stopBuffering();
echo "\n=== phar atualizado (round 5) ===\n";
