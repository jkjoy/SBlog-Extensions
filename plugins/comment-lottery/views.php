<?php

declare(strict_types=1);

if (!defined('PLUGINS_DIR')) {
    http_response_code(403);
    exit;
}

function sblog_lottery_current_post(): ?array
{
    if (($GLOBALS['sblog_current_action'] ?? '') !== 'post') {
        return null;
    }
    $identifier = isset($_GET['slug']) ? sblog_lottery_input($_GET, 'slug') : sblog_lottery_input($_GET, 'id');
    return fetch_post_by_identifier($identifier, is_admin());
}

function sblog_lottery_excerpt(array $fields, array $context): array
{
    $content = (string)($context['content'] ?? '');
    if (($context['kind'] ?? '') !== 'post' || trim((string)($fields['excerpt'] ?? '')) !== '' || sblog_lottery_tokens($content) === []) {
        return $fields;
    }
    $content = preg_replace('/^\s*\[comment-lottery id="[a-f0-9]{32}"\]\s*$/m', '', $content) ?? $content;
    $fields['excerpt'] = derive_excerpt($content);
    if ($fields['excerpt'] === '') {
        $fields['excerpt'] = '评论抽奖活动，参与规则与结果详见文章。';
    }
    return $fields;
}

function sblog_lottery_render(array $lottery): string
{
    $now = time();
    $drawn = $lottery['status'] === 'drawn';
    $status = $drawn ? '已开奖' : ($now < (int)$lottery['starts_at'] ? '尚未开始'
        : ($now < (int)$lottery['ends_at'] ? '正在进行' : ((int)$lottery['auto_draw'] === 1 ? '等待自动开奖' : '已结束 · 等待开奖')));
    $winners = $drawn ? sblog_lottery_winners($lottery['id']) : [];
    $candidateCount = $drawn ? (int)$lottery['candidate_count'] : count(sblog_lottery_candidates($lottery));
    // This renderer runs inside the core output handler, where nested buffers are prohibited.
    $id = h($lottery['id']);
    $html = '<section class="sblog-lottery" id="lottery-' . $id . '" aria-labelledby="lottery-title-' . $id . '">'
        . '<p class="sblog-lottery__eyebrow">评论抽奖</p><div class="sblog-lottery__header"><h2 id="lottery-title-' . $id . '">'
        . h($lottery['title']) . '</h2><span class="sblog-lottery__status">' . h($status) . '</span></div>';
    if ($lottery['description'] !== '') {
        $html .= '<p class="sblog-lottery__description">' . h($lottery['description']) . '</p>';
    }
    $html .= '<dl class="sblog-lottery__stats">';
    foreach (['starts_at' => '开始时间', 'ends_at' => '结束时间'] as $field => $label) {
        $timestamp = (int)$lottery[$field];
        $html .= '<div><dt>' . $label . ' · 北京时间</dt><dd><time datetime="' . h(sblog_lottery_date($timestamp, DATE_ATOM))
            . '">' . h(sblog_lottery_date($timestamp)) . '</time></dd></div>';
    }
    $html .= '<div><dt>中奖名额</dt><dd>' . h((int)$lottery['winners_count']) . ' 条评论</dd></div></dl>'
        . '<p class="sblog-lottery__rules">在活动时间内评论本篇文章即可参与。只统计开奖时已审核通过、填写有效邮箱的访客'
        . ((int)$lottery['roots_only'] === 1 ? '主评论，不包含回复' : '评论，包含回复') . '。'
        . ((int)$lottery['unique_email'] === 1 ? '同一邮箱仅保留最早的一条合格评论。' : '每条合格评论各有一次机会，同一邮箱可能重复中奖。')
        . '截止时间不包含在参与范围内。</p>';
    if ($drawn) {
        if ($winners) {
            $html .= '<h3>中奖评论公示</h3><ol class="sblog-lottery__winners">';
            $statement = db()->prepare("SELECT id FROM comments WHERE id=? AND post_id=? AND status='approved'");
            foreach ($winners as $winner) {
                $commentId = (int)$winner['comment_id'];
                $statement->execute([$commentId, (int)$lottery['post_id']]);
                if ($statement->fetchColumn() !== false) {
                    $html .= '<li><a class="sblog-lottery__winner-name" href="#comment-' . h($commentId) . '">'
                        . h($winner['author_name']) . ' · 评论 #' . h($commentId) . '</a><span class="sblog-lottery__winner-comment">'
                        . h(str_sub_u($winner['content'], 0, 240)) . (str_len_u($winner['content']) > 240 ? '…' : '') . '</span></li>';
                } else {
                    $html .= '<li><span class="sblog-lottery__winner-name">中奖评论 #' . h($commentId)
                        . '</span><span class="sblog-lottery__winner-comment">该评论已撤下，中奖记录保留。</span></li>';
                }
            }
            $html .= '</ol>';
        } else {
            $html .= '<p class="sblog-lottery__empty">活动已结束，没有符合条件的评论，本次无中奖人。</p>';
        }
        $html .= '<p class="sblog-lottery__footer">' . h(sblog_lottery_date((int)$lottery['drawn_at'])) . ' 开奖 · '
            . h($candidateCount) . ' 条合格评论 · 实际中奖 ' . h(count($winners)) . ' 条。'
            . (count($winners) < (int)$lottery['winners_count'] && $winners ? '合格评论不足，按实际数量开奖。' : '')
            . ((int)$lottery['notify'] === 1 && $winners ? '中奖通知通过评论中填写的邮箱发送，请留意收件箱。' : '') . '</p>';
    } else {
        $html .= '<p class="sblog-lottery__footer">当前 ' . h($candidateCount) . ' 条合格评论。'
            . ((int)$lottery['auto_draw'] === 1 ? '活动结束后自动开奖，结果在这里公示。' : '活动结束后由博主开奖，结果在这里公示。')
            . ((int)$lottery['notify'] === 1 ? '请填写可接收中奖通知的邮箱，邮箱不会公开。' : '') . '</p>';
    }
    return $html . '</section>';
}

function sblog_lottery_output(string $html, array $context): string
{
    if (($context['action'] ?? '') !== 'post' || !str_contains($html, '</html>')) {
        return $html;
    }
    $post = sblog_lottery_current_post();
    $lottery = $post ? sblog_lottery_for_post((int)$post['id']) : null;
    if (!$lottery || !in_array($lottery['id'], sblog_lottery_tokens((string)$post['content']), true)) {
        return $html;
    }
    // Keep this article dynamic, including a locked password/reply gate.
    if (function_exists('spc_release_plan_lock')) {
        spc_release_plan_lock();
        unset($GLOBALS['sblog_static_page_cache_plan']);
    }
    $pattern = '~<p>\s*\[comment-lottery id=(?:"|&quot;)' . preg_quote($lottery['id'], '~') . '(?:"|&quot;)\]\s*</p>~';
    return preg_replace_callback($pattern, static fn(): string => sblog_lottery_render($lottery), $html, 1) ?? $html;
}

function sblog_lottery_badge(string $html, array $context): string
{
    static $winnersByPost = [];
    $postId = (int)($context['post']['id'] ?? 0);
    $commentId = (int)($context['comment']['id'] ?? 0);
    if ($postId < 1 || $commentId < 1) {
        return $html;
    }
    if (!array_key_exists($postId, $winnersByPost)) {
        $lottery = sblog_lottery_for_post($postId);
        $winnersByPost[$postId] = $lottery && $lottery['status'] === 'drawn'
            ? array_map('intval', array_column(sblog_lottery_winners($lottery['id']), 'comment_id')) : [];
    }
    return in_array($commentId, $winnersByPost[$postId], true)
        ? $html . '<span class="sblog-lottery-winner-badge">抽奖中奖</span>' : $html;
}

function sblog_lottery_bypass_cache(): void
{
    if (!function_exists('spc_request_descriptor')) {
        return;
    }
    $post = sblog_lottery_current_post();
    if (!$post || !sblog_lottery_for_post((int)$post['id'])) {
        return;
    }
    // Delete any older cached response before the cache plugin can serve and exit.
    $descriptor = spc_request_descriptor('post');
    if ($descriptor !== null) {
        $key = spc_cache_key($descriptor, spc_dependency_signature(spc_settings(), $descriptor));
        spc_remove_cache_pair($key);
    }
}
