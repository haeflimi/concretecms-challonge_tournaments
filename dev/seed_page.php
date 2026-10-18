<?php
// Dev only: a page /turniere with the three blocks (Tournament List → Tournament Overview via ?tournament=, My Matches)
// so the package can be looked at without editing pages. Safe to run again (the page is created once).
// Run: docker exec -u www-data devturicanech-app-1 php /app/concrete/bin/concrete c5:exec --no-interaction /app/packages/challonge_tournaments/dev/seed_page.php

use Concrete\Core\Block\BlockType\BlockType;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\Template as PageTemplate;
use Concrete\Core\Page\Type\Type as PageType;

$out = static function (string $line) {
    echo $line, PHP_EOL;
};
$page = Page::getByPath('/turniere');
if ($page && !$page->isError()) {
    $out('Page /turniere exists (cID ' . $page->getCollectionID() . ').');

    return;
}
$home = Page::getByID(1);
$type = PageType::getByHandle('page') ?: (PageType::getList()[0] ?? null);
$template = PageTemplate::getByHandle('full') ?: PageTemplate::getByHandle('default') ?: (PageTemplate::getList()[0] ?? null);
if (!$type || !$template) {
    $out('No page type / template found.');

    return;
}
$page = $home->add($type, ['cName' => 'Turniere', 'cHandle' => 'turniere', 'cDescription' => 'Dev: Challonge-Turniere'], $template);
$out('Created page /turniere (cID ' . $page->getCollectionID() . ', template ' . $template->getPageTemplateHandle() . ').');

$area = 'Main';
$list = BlockType::getByHandle('tournament_list');
$overview = BlockType::getByHandle('tournament_overview');
$mine = BlockType::getByHandle('my_matches');
if ($list) {
    $page->addBlock($list, $area, ['mode' => 'all', 'showCompleted' => 1, 'detailPageID' => $page->getCollectionID(), 'title' => 'Turniere', 'tournamentIDs' => '']);
}
if ($mine) {
    $page->addBlock($mine, $area, ['mode' => 'all', 'showCompleted' => 0, 'showFinishedMatches' => 1, 'title' => 'Meine Matches', 'tournamentIDs' => '']);
}
if ($overview) {
    $page->addBlock($overview, $area, ['mode' => 'all', 'showCompleted' => 1, 'showParticipants' => 1, 'showMatches' => 1, 'showStandings' => 1, 'showBracket' => 1, 'bracketHeight' => 500, 'refreshSeconds' => 60, 'tournamentIDs' => '']);
}
$out('Blocks added to area "' . $area . '". Open /turniere (sign in as tourney01 / tourney for the captain view).');
