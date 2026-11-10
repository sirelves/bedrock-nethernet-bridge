<?php
// Round 8: CraftingDataCache envia netId = indice + RECIPE_ID_OFFSET(1), mas o
// ItemStackRequestExecutor::beginCrafting buscava getCraftingRecipeFromIndex($recipeId) sem descontar.
// Resultado: todo craft pelo livro de receitas usava a receita VIZINHA (ex.: tabuas x4 -> receita x3).
// Vale para todas as versoes (o netId e sempre indice+1).
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
$f = 'src/network/mcpe/handler/ItemStackRequestExecutor.php';
$c = file_get_contents("phar://$phar/$f");
patch($c,
	"\t\t\$recipe = \$craftingManager->getCraftingRecipeFromIndex(\$recipeId);\n",
	"\t\t\$recipe = \$craftingManager->getCraftingRecipeFromIndex(\$recipeId - \\pocketmine\\network\\mcpe\\cache\\CraftingDataCache::RECIPE_ID_OFFSET);\n",
	"beginCrafting: desconta RECIPE_ID_OFFSET");
lint($c, $f);
if(($argv[2] ?? '') === '--dry'){ echo "\n=== dry-run OK ===\n"; exit(0); }
$p = new Phar($phar);
$p->startBuffering();
$p[$f] = $c;
$p->stopBuffering();
echo "\n=== phar atualizado (round 8) ===\n";
