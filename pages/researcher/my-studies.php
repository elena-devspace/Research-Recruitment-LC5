<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/core/auth.php';
require_once __DIR__ . '/../../app/recruitment/study-capacity.php';

$user = requireRole('academic');
$deletedTitle = $_SESSION['deleted_study_title'] ?? null;
unset($_SESSION['deleted_study_title']);
$pdo = getConnection();

$closedStudyId = $_SESSION['closed_study_id'] ?? null;
unset($_SESSION['closed_study_id']);

$verificationStmt = $pdo->prepare(
    "SELECT verification_status
     FROM academic_profiles
     WHERE user_id = ?"
);

$verificationStmt->execute([$user['user_id']]);

$canPublish =
    $verificationStmt->fetchColumn() === 'verified';

$publishedId = $_SESSION['published_study_id'] ?? null;
unset($_SESSION['published_study_id']);

function e(?string $value): string {
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

// Only retrieve studies belonging to this researcher.
$stmt = $pdo->prepare(
    "SELECT *
     FROM studies
     WHERE researcher_id = :researcher_id
     ORDER BY created_at DESC, study_id DESC"
);

$stmt->execute([
    ':researcher_id' => $user['user_id']
]);

$studies = $stmt->fetchAll();
$capacityByStudy = [];
foreach ($studies as $study) {
    $studyId = (int)$study['study_id'];
    $capacityByStudy[$studyId] = getStudyCapacity($pdo, $studyId);
}

$questionStmt = $pdo->prepare(
    'SELECT q.study_id, q.question_text, q.required_answer
     FROM study_eligibility_questions q
     INNER JOIN studies s ON s.study_id = q.study_id
     WHERE s.researcher_id = ?
     ORDER BY q.study_id, q.position'
);
$questionStmt->execute([$user['user_id']]);
$questionsByStudy = [];
foreach ($questionStmt->fetchAll(PDO::FETCH_ASSOC) as $question) {
    $questionsByStudy[(int)$question['study_id']][] = $question;
}


if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$statusCounts = ['draft' => 0, 'open' => 0, 'closed' => 0];
foreach ($studies as $study) {
    $status = (string)$study['status'];
    if (array_key_exists($status, $statusCounts)) {
        $statusCounts[$status]++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Research Studies | Research Recruitment</title>
    <link rel="stylesheet" href="../../assets/css/site.css">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <link rel="stylesheet" href="../../assets/css/my-studies.css?v=popup-polish-2">
    <!-- Self-contained confirmation-dialog styles to prevent stale CSS from showing an unstyled native dialog. -->
    <style>
        dialog#study-action-dialog {
            position: fixed;
            inset: 0;
            margin: auto;
            width: min(100% - 32px, 485px);
            max-width: 485px;
            max-height: min(90vh, 690px);
            overflow-y: auto;
            padding: 0;
            border: 1px solid #d8e5f0;
            border-radius: 18px;
            background: #fff;
            color: #2f3b53;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 14px;
            line-height: 1.5;
            box-shadow: 0 25px 85px rgba(18, 35, 65, .29);
        }
        dialog#study-action-dialog::backdrop {
            background: rgba(24, 38, 64, .54);
            backdrop-filter: blur(2px);
        }
        #study-action-dialog .studies-modal__head {
            display: flex;
            align-items: flex-start;
            gap: 13px;
            padding: 27px 27px 16px;
        }
        #study-action-dialog .studies-modal__icon {
            display: grid;
            place-items: center;
            flex: 0 0 43px;
            width: 43px;
            height: 43px;
            border-radius: 13px;
            background: #eaf6ff;
            color: #335c83;
            font-weight: 800;
            font-size: 22px;
        }
        #study-action-dialog[data-tone="danger"] .studies-modal__icon {
            background: #fff0eb;
            color: #a23f32;
        }
        #study-action-dialog .studies-modal__heading-wrap {
            flex: 1;
            min-width: 0;
            padding-top: 1px;
        }
        #study-action-dialog .studies-modal__eyebrow {
            display: block;
            margin: 0 0 3px;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .14em;
            text-transform: uppercase;
            color: #617693;
        }
        #study-action-dialog .studies-modal__heading-wrap h2 {
            margin: 0;
            font-size: 24px;
            line-height: 1.3;
            letter-spacing: -.02em;
            color: #263853;
        }
        #study-action-dialog .studies-modal__close {
            display: grid;
            place-items: center;
            flex: 0 0 29px;
            width: 29px;
            height: 29px;
            margin: -4px -5px 0 0;
            padding: 0;
            border: 0;
            border-radius: 8px;
            background: transparent;
            color: #64738a;
            font-size: 24px;
            line-height: 1;
            cursor: pointer;
        }
        #study-action-dialog .studies-modal__close:hover {
            background: #edf4f9;
        }
        #study-action-dialog .studies-modal__body {
            padding: 0 27px 24px;
        }
        #study-action-dialog .studies-modal__label {
            margin: 0 0 7px;
            color: #62758c;
            font-size: 12px;
            font-weight: 700;
        }
        #study-action-dialog .studies-dialog__study {
            margin: 0 0 17px;
            padding: 12px 14px;
            border: 1px solid #d6e8f5;
            border-radius: 10px;
            background: #eef8ff;
            color: #263853;
            font-size: 14px;
            font-weight: 700;
            overflow-wrap: anywhere;
        }
        #study-action-dialog .studies-dialog__description {
            margin: 0;
            color: #576980;
            font-size: 13px;
            line-height: 1.65;
        }
        #study-action-dialog .studies-dialog__actions {
            display: flex;
            flex-flow: row nowrap;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            margin: 0;
            padding: 17px 27px;
            border-top: 1px solid #e5edf5;
            background: #f9fbfd;
        }
        #study-action-dialog .studies-dialog__actions form {
            display: inline-flex;
            flex: 0 0 auto;
            width: auto;
            margin: 0;
            padding: 0;
        }
        #study-action-dialog .studies-dialog__actions .btn {
            display: inline-flex;
            flex: 0 0 auto;
            width: auto;
            min-height: 40px;
            margin: 0;
            padding: 10px 16px;
            border: 1px solid transparent;
            border-radius: 8px;
            font: inherit;
            font-weight: 700;
            font-size: 13px;
            line-height: 1.25;
            white-space: normal;
            text-align: center;
            cursor: pointer;
        }
        #study-action-dialog .studies-dialog__actions .studies-btn--secondary {
            background: #fff;
            color: #2f3b53;
            border-color: #c8d9e8;
        }
        #study-action-dialog .studies-dialog__actions .studies-btn--secondary:hover {
            background: #eaf6ff;
        }
        #study-action-dialog .studies-dialog__actions .btn--primary {
            background: #ffc943;
            color: #2f3b53;
            border-color: #ffc943;
        }
        #study-action-dialog .studies-dialog__actions .btn--primary:hover {
            background: #ffda79;
        }
        #study-action-dialog .studies-dialog__actions .studies-btn--danger-solid {
            background: #a34238;
            color: #fff;
            border-color: #a34238;
        }
        #study-action-dialog .studies-dialog__actions .studies-btn--danger-solid:hover {
            background: #83352d;
        }
        #study-action-dialog button:focus-visible {
            outline: 3px solid #72b4ed;
            outline-offset: 3px;
        }
        @media (max-width: 440px) {
            #study-action-dialog .studies-modal__head { padding: 22px 20px 14px; gap: 11px; }
            #study-action-dialog .studies-modal__body { padding: 0 20px 20px; }
            #study-action-dialog .studies-dialog__actions { padding: 15px 20px; }
            #study-action-dialog .studies-modal__heading-wrap h2 { font-size: 21px; }
            #study-action-dialog .studies-dialog__actions .btn { min-height: 42px; }
        }
        @media (max-width: 350px) {
            #study-action-dialog .studies-dialog__actions,
            #study-action-dialog .studies-dialog__actions form { flex-direction: column; width: 100%; }
            #study-action-dialog .studies-dialog__actions .btn { width: 100%; }
        }
    </style>
</head>
<body>
<header class="site-header">
    <div class="site-header__inner">
        <a class="brand" href="../../index.php" aria-label="Research Recruitment homepage">
            <span class="brand__mark">LOGO</span>
            <span class="brand__name">Research Recruitment</span>
        </a>
        <nav class="site-nav dashboard-nav" aria-label="Main navigation">
            <a href="academic-dashboard.php">Dashboard</a>
            <a href="my-studies.php" aria-current="page">My studies</a>
            <a href="review-applications.php">Applications</a>
            <a href="find-volunteers.php">Find volunteers</a>
            <a href="my-sent-requests.php">Invitations</a>
            <a href="../shared/profile.php">My profile</a>
            <form action="../../actions/account/logout.php" method="post" class="dashboard-logout">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <button type="submit" class="dashboard-logout__button">Log out</button>
            </form>
        </nav>
    </div>
</header>

<main class="page-shell studies-page">
    <section class="studies-hero" aria-labelledby="studies-title">
        <div>
            <span class="eyebrow">Your research workspace</span>
            <h1 id="studies-title">Manage your research studies</h1>
            <p>Welcome back, <?= e($user['first_name']) ?>! Manage your drafts, track recruitment and review your published studies.</p>
        </div>
        <a class="btn btn--primary" href="create-study.php">Create a research study</a>
    </section>

    <?php if (is_int($closedStudyId)): ?>
        <div class="feedback feedback--success studies-feedback" role="status">Study #<?= $closedStudyId ?> closed successfully!</div>
    <?php endif; ?>
    <?php if (is_int($publishedId)): ?>
        <div class="feedback feedback--success studies-feedback" role="status">Study #<?= $publishedId ?> published successfully!</div>
    <?php endif; ?>
    <?php if (is_string($deletedTitle)): ?>
        <div class="feedback feedback--success studies-feedback" role="status">Draft “<?= e($deletedTitle) ?>” deleted successfully!</div>
    <?php endif; ?>

    <section class="studies-overview" aria-labelledby="studies-overview-title">
        <div class="studies-section-header">
            <div>
                <span class="eyebrow">Study overview</span>
                <h2 id="studies-overview-title">Your research at a glance</h2>
            </div>
            <p>Live totals from your research studies.</p>
        </div>
        <div class="studies-stats">
            <div class="studies-stat"><span>Total studies</span><strong><?= count($studies) ?></strong><small>All studies you've created</small></div>
            <div class="studies-stat"><span>Drafts</span><strong><?= $statusCounts['draft'] ?></strong><small>Preparing to publish</small></div>
            <div class="studies-stat"><span>Open</span><strong><?= $statusCounts['open'] ?></strong><small>Currently recruiting</small></div>
            <div class="studies-stat"><span>Closed</span><strong><?= $statusCounts['closed'] ?></strong><small>Recruitment ended</small></div>
        </div>
    </section>

    <section class="studies-list" aria-labelledby="studies-list-title">
        <div class="studies-section-header">
            <div>
                <span class="eyebrow">Your study portfolio</span>
                <h2 id="studies-list-title">My research studies</h2>
            </div>
            <p>Newest studies appear first.</p>
        </div>

        <?php if (empty($studies)): ?>
            <div class="studies-empty">
                <span class="studies-empty__icon" aria-hidden="true">01</span>
                <h3>You haven't created any studies yet</h3>
                <p>Start with a draft. You can review your research details before publishing.</p>
                <a class="btn btn--primary" href="create-study.php">Create your first study</a>
            </div>
        <?php else: ?>
            <div class="studies-grid">
                <?php foreach ($studies as $study): ?>
                    <?php
                        $studyId = (int)$study['study_id'];
                        $status = (string)$study['status'];
                        $statusClass = in_array($status, ['draft', 'open', 'closed'], true) ? $status : 'unknown';
                        $capacity = $capacityByStudy[$studyId];
                        $screeningQuestions = $questionsByStudy[$studyId] ?? [];
                        $format = match ((string)($study['participation_format'] ?? '')) {
                            'in_person' => 'In person',
                            'online' => 'Online',
                            'hybrid' => 'Hybrid',
                            default => ucfirst(str_replace('_', ' ', (string)($study['participation_format'] ?? 'Not specified')))
                        };
                        $availability = match ((string)($study['required_availability'] ?? '')) {
                            'weekdays' => 'Weekdays',
                            'weekends' => 'Weekends',
                            'both' => 'Both weekdays and weekends',
                            default => 'Not specified'
                        };
                    ?>
                    <article class="study-card" aria-labelledby="study-title-<?= $studyId ?>">
                        <div class="study-card__badges">
                            <span class="study-card__topic"><?= e((string)$study['topic']) ?></span>
                            <span class="study-card__tag"><?= e($format) ?></span>
                            <?php if ((int)($study['is_test'] ?? 0) === 1): ?>
                                <span class="study-card__tag">Fictional test study</span>
                            <?php endif; ?>
                            <span class="study-card__status study-card__status--<?= $statusClass ?>"><?= e(ucfirst($status)) ?></span>
                        </div>

                        <h3 id="study-title-<?= $studyId ?>"><?= e($study['title']) ?></h3>
                        <p class="study-card__subtitle">Study #<?= $studyId ?> · <?= $status === 'draft' ? 'Not yet published' : ($status === 'open' ? 'Recruitment open' : 'Recruitment closed') ?></p>

                        <dl class="study-card__summary">
                            <div><dt>Start date</dt><dd><?= e($study['start_date'] ?? 'Not set') ?></dd></div>
                            <div><dt>End date</dt><dd><?= e($study['end_date'] ?? 'Not set') ?></dd></div>
                            <div><dt>Application deadline</dt><dd><?= e($study['application_deadline'] ?? 'Not specified') ?></dd></div>
                            <div><dt>Reimbursement</dt><dd>£<?= number_format((float)$study['reimbursement_amount'], 2) ?></dd></div>
                        </dl>

                        <div class="study-card__capacity">
                            <div class="study-card__capacity-heading"><strong>Participant capacity</strong><span><?= $capacity['capacity'] === null ? 'No limit set' : (int)$capacity['accepted'] . ' of ' . (int)$capacity['capacity'] . ' accepted' ?></span></div>
                            <div class="study-card__capacity-metrics">
                                <span>Accepted: <strong><?= (int)$capacity['accepted'] ?></strong></span>
                                <span>Spaces remaining: <strong><?= $capacity['remaining'] === null ? 'No limit' : (int)$capacity['remaining'] ?></strong></span>
                            </div>
                            <?php if ($capacity['capacity'] !== null): ?>
                                <?php $capacityTotal = max(1, (int)$capacity['capacity']); ?>
                                <div class="study-card__progress" role="progressbar" aria-label="Accepted participants" aria-valuemin="0" aria-valuemax="<?= $capacityTotal ?>" aria-valuenow="<?= min((int)$capacity['accepted'], $capacityTotal) ?>">
                                    <span style="width: <?= min(100, max(0, (int)round(100 * (int)$capacity['accepted'] / $capacityTotal))) ?>%"></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <details class="study-card__expand">
                            <summary>View study details</summary>
                            <div class="study-card__expanded-content">
                                <section><h4>Research aim</h4><p><?= nl2br(e($study['aim'])) ?></p></section>
                                <section><h4>Study description</h4><p><?= nl2br(e($study['description'])) ?></p></section>
                                <section><h4>Participant tasks</h4><p><?= nl2br(e($study['participation_tasks'])) ?></p></section>
                                <section><h4>Eligibility requirements</h4><p><?= nl2br(e($study['eligibility_requirements'])) ?></p></section>
                                <section>
                                    <h4>Matching requirements</h4>
                                    <p><strong>Availability:</strong> <?= e($availability) ?></p>
                                    <p><strong>Age range:</strong> <?= e((string)($study['minimum_age'] ?? 'Not specified')) ?> to <?= e((string)($study['maximum_age'] ?? 'No upper limit')) ?></p>
                                </section>
                                <section>
                                    <h4>Eligibility screening questions</h4>
                                    <?php if (empty($screeningQuestions)): ?>
                                        <p>None specified.</p>
                                    <?php else: ?>
                                        <ol>
                                            <?php foreach ($screeningQuestions as $question): ?>
                                                <li><?= e($question['question_text']) ?> — Required: <?= e(ucfirst($question['required_answer'])) ?></li>
                                            <?php endforeach; ?>
                                        </ol>
                                    <?php endif; ?>
                                </section>
                                <section>
                                    <h4>Location and distance matching</h4>
                                    <p><strong>Location:</strong> <?= e($study['location'] ?? 'Not specified') ?></p>
                                    <p><strong>Study postcode:</strong> <?= e($study['study_postcode'] ?? 'Not specified') ?></p>
                                    <p><strong>Distance search radius:</strong> <?= $study['max_distance_miles'] === null ? 'Not specified' : (int)$study['max_distance_miles'] . ' miles' ?></p>
                                </section>
                                <section>
                                    <h4>Reimbursement details</h4>
                                    <p><?= e($study['reimbursement_details'] ?? 'Not specified') ?></p>
                                </section>
                                <section>
                                    <h4>Anonymous participation</h4>
                                    <p><?= $study['anonymous_participation_allowed'] ? 'Allowed' : 'Not allowed' ?></p>
                                </section>
                            </div>
                        </details>

                        <div class="study-card__actions">
                            <?php if ($status === 'draft'): ?>
                                <a class="btn studies-btn--secondary" href="edit-study.php?id=<?= $studyId ?>">Edit study</a>
                                <?php if ($canPublish): ?>
                                    <a class="btn btn--primary" href="../../actions/studies/publish-study.php?id=<?= $studyId ?>" data-study-action="publish">Publish study</a>
                                <?php else: ?>
                                    <p class="study-card__hint">Researcher verification is required before publishing.</p>
                                <?php endif; ?>
                                <a class="btn studies-btn--danger" href="../../actions/studies/delete-study.php?id=<?= $studyId ?>" data-study-action="delete">Delete draft</a>
                            <?php elseif ($status === 'open'): ?>
                                <a class="btn studies-btn--danger" href="../../actions/studies/close-study.php?id=<?= $studyId ?>" data-study-action="close">Close study</a>
                            <?php else: ?>
                                <span class="study-card__closed-note">This study is no longer accepting applications.</span>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="studies-shortcuts" aria-label="Academic researcher shortcuts">
        <div>
            <h2>Keep your research organised</h2>
            <p>Review incoming applications, find volunteers or return to your dashboard.</p>
        </div>
        <div class="studies-shortcuts__links">
            <a class="btn studies-btn--light" href="review-applications.php">Applications</a>
            <a class="btn studies-btn--light" href="find-volunteers.php">Find volunteers</a>
            <a class="btn studies-btn--light" href="academic-dashboard.php">Dashboard</a>
        </div>
    </section>
</main>

<!-- The links remain functional without JavaScript, using the existing confirmation pages. -->
<dialog class="studies-dialog" id="study-action-dialog" aria-labelledby="study-dialog-title" aria-describedby="study-dialog-description">
    <div class="studies-modal__head">
        <span class="studies-modal__icon" id="study-dialog-icon" aria-hidden="true">?</span>
        <div class="studies-modal__heading-wrap">
            <span class="studies-modal__eyebrow">Confirm study action</span>
            <h2 id="study-dialog-title">Confirm action</h2>
        </div>
        <button type="button" class="studies-modal__close" id="study-dialog-close" aria-label="Close confirmation">&times;</button>
    </div>
    <div class="studies-modal__body">
        <p class="studies-modal__label">Selected study</p>
        <p class="studies-dialog__study" id="study-dialog-study"></p>
        <p class="studies-dialog__description" id="study-dialog-description"></p>
    </div>
    <div class="studies-dialog__actions">
        <button class="btn studies-btn--secondary" type="button" id="study-dialog-cancel">Cancel</button>
        <form method="post" action="" id="study-dialog-form">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <button type="submit" class="btn btn--primary" id="study-dialog-confirm">Confirm</button>
        </form>
    </div>
</dialog>

<script>
(() => {
    const dialog = document.getElementById('study-action-dialog');
    if (!dialog || typeof dialog.showModal !== 'function') return;

    const heading = document.getElementById('study-dialog-title');
    const studyName = document.getElementById('study-dialog-study');
    const description = document.getElementById('study-dialog-description');
    const confirmButton = document.getElementById('study-dialog-confirm');
    const form = document.getElementById('study-dialog-form');
    const cancelButton = document.getElementById('study-dialog-cancel');
    const closeButton = document.getElementById('study-dialog-close');
    const icon = document.getElementById('study-dialog-icon');

    const actions = {
        publish: {
            heading: 'Publish this study?',
            description: 'This will make your study available for volunteer recruitment once publication checks pass.',
            button: 'Yes, publish study',
            dangerous: false
        },
        close: {
            heading: 'Close this study?',
            description: 'This will stop new applications and invitations. Your existing study data will be kept.',
            button: 'Yes, close study',
            dangerous: true
        },
        delete: {
            heading: 'Permanently delete this draft?',
            description: 'This cannot be undone. Only draft studies can be deleted.',
            button: 'Yes, delete permanently',
            dangerous: true
        }
    };

    document.querySelectorAll('a[data-study-action]').forEach(link => {
        link.addEventListener('click', event => {
            const action = actions[link.dataset.studyAction];
            if (!action) return;

            const card = link.closest('.study-card');
            const title = card?.querySelector('h3')?.textContent?.trim() || 'Selected study';
            event.preventDefault();

            heading.textContent = action.heading;
            studyName.textContent = title;
            description.textContent = action.description;
            confirmButton.textContent = action.button;
            icon.textContent = action.dangerous ? '!' : '✓';
            dialog.dataset.tone = action.dangerous ? 'danger' : 'primary';
            confirmButton.classList.toggle('studies-btn--danger-solid', action.dangerous);
            confirmButton.classList.toggle('btn--primary', !action.dangerous);
            form.action = link.href;
            dialog.showModal();
        });
    });

    cancelButton.addEventListener('click', () => dialog.close());
    closeButton.addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', event => {
        if (event.target === dialog) dialog.close();
    });
})();
</script>

<footer class="site-footer">
    <div class="site-footer__inner">
        <span>© 2026 Research Recruitment · KV6014 university project</span>
        <div class="site-footer__items" aria-label="Additional information">
            <span>Privacy Policy</span><span>Contact</span><span>Terms</span>
        </div>
    </div>
</footer>
</body>
</html>
