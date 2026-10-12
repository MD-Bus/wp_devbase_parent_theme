<?php
// WordPress's required fallback template — the last resort in the
// template hierarchy when no more specific template matches. This theme
// has no header.php/footer.php/page.php/single.php yet (each project
// copies this base theme and builds its own), so right now this is the
// ONLY thing that ever renders on the front end. Exists purely so
// visiting a fresh copy of this theme shows a real branded placeholder
// instead of a blank white page — replace/extend once a project starts
// building its own templates.

$context = Timber\Timber::context();

Timber\Timber::render( 'index.twig', $context );
