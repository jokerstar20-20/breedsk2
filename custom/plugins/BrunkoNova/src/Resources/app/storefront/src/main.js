// BrunkoNova: po prepnutí farby v karte produktu (AJAX výmena karty) ostávali na stránke "visieť" tooltipy
// s názvom farby (Bootstrap tooltip patrí elementu, ktorý sa medzitým nahradil). Po výmene karty ich odstránime.
(function () {
    'use strict';

    function removeStaleTooltips() {
        document.querySelectorAll('.tooltip').forEach(function (tooltip) {
            tooltip.remove();
        });
    }

    function isVariantSwitcher(node) {
        return node && node.nodeType === 1 && (
            node.matches('.product-detail-configurator, .product-box')
            || node.querySelector('.product-detail-configurator-option-label')
        );
    }

    // Po kliknutí na farbu (po dobehnutí AJAX výmeny karty).
    document.addEventListener('click', function (event) {
        if (event.target.closest && event.target.closest('.product-box .product-detail-configurator')) {
            [0, 250, 800, 1600].forEach(function (delay) {
                setTimeout(removeStaleTooltips, delay);
            });
        }
    }, true);

    // Poistka: keď sa odstráni prepínač farieb z DOM (výmena karty), osirelé tooltipy zmiznú.
    new MutationObserver(function (mutations) {
        for (var i = 0; i < mutations.length; i++) {
            var removed = mutations[i].removedNodes;
            for (var j = 0; j < removed.length; j++) {
                if (isVariantSwitcher(removed[j])) {
                    removeStaleTooltips();

                    return;
                }
            }
        }
    }).observe(document.body, { childList: true, subtree: true });
})();
