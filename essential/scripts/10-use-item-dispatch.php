<?php
// Round 4: no 2225 o ItemInteraction do PlayerAuthInput volta a carregar a acao de uso
// (BREAK_BLOCK etc). O Essential ignorava a transacao para >= 1.26.30 -> bloco nao quebrava/dropava.
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
$f = 'src/network/mcpe/handler/InGamePacketHandler.php';
$c = file_get_contents("phar://$phar/$f");
patch($c,
	"\t\t\tif(\$this->session->getProtocolId() >= ProtocolInfo::PROTOCOL_1_26_30){\n\t\t\t\t//1.26.30+ no longer carries use-item data",
	"\t\t\tif(\$this->session->getProtocolId() >= ProtocolInfo::PROTOCOL_1_26_30 && \$this->session->getProtocolId() < ProtocolInfo::PROTOCOL_1_26_62){\n\t\t\t\t//2225 (1.26.60+) voltou a mandar os dados de uso no ItemInteraction: cai no handleUseItemTransaction\n\t\t\t\t//1.26.30+ no longer carries use-item data",
	"InGamePacketHandler: 2225 processa ItemInteraction do PlayerAuthInput");
lint($c, $f);
if(($argv[2] ?? '') === '--dry'){ echo "\n=== dry-run OK ===\n"; exit(0); }
$p = new Phar($phar);
$p->startBuffering();
$p[$f] = $c;
$p->stopBuffering();
echo "\n=== phar atualizado (round 4) ===\n";
