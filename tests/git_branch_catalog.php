<?php
use App\Services\GitBranchCatalog;
check(GitBranchCatalog::date('2026-11-02T13:00:00+02:00')==='2026-11-02T11:00:00Z','dates de branches normalisées en UTC');
check(GitBranchCatalog::date('2026-02-30T00:00:00Z')===null && GitBranchCatalog::date('not-a-date')===null,'dates de branches invalides restent inconnues');
$catalogSorted=GitBranchCatalog::sorted([['name'=>'unknown','last_commit_at'=>null],['name'=>'old','last_commit_at'=>'2025-01-01T00:00:00Z'],['name'=>'recent-2','last_commit_at'=>'2026-11-02T00:00:00Z'],['name'=>'recent-1','last_commit_at'=>'2026-11-02T00:00:00Z']]);
check(array_column($catalogSorted,'name')===['recent-1','recent-2','old','unknown'],'dates décroissantes avec ordre déterministe et inconnues en fin');
