(function (Icinga) {
    'use strict';

    Icinga.availableModules.director_rollback = function (module) {
        const forms = new WeakMap();
        const inputName = 'objects[]';

        function include(roots, links) {
            const selected = new Set(roots);
            const queue = Array.from(roots);
            for (let index = 0; index < queue.length; index++) {
                for (const dependency of links[queue[index]] || []) {
                    const id = String(dependency);
                    if (!selected.has(id)) {
                        selected.add(id);
                        queue.push(id);
                    }
                }
            }
            return selected;
        }

        module.on('change', 'input[data-rollback-choice]', function (event) {
            const input = event.currentTarget;
            const form = input.form;
            if (!forms.has(form)) {
                const choices = Array.from(form.querySelectorAll('input[data-rollback-choice]'));
                const roots = new Set(choices.filter(choice => choice.checked && choice !== input)
                    .map(choice => choice.value));
                forms.set(form, { choices, roots, links: JSON.parse(form.dataset.rollbackLinks) });
            }

            const { choices, roots, links } = forms.get(form);
            if (input.checked) {
                roots.add(input.value);
            } else {
                for (const root of roots) {
                    if (include([root], links).has(input.value)) {
                        roots.delete(root);
                    }
                }
            }

            const selected = include(roots, links);
            for (const choice of choices) {
                choice.checked = selected.has(choice.value);
                if (roots.has(choice.value)) {
                    choice.name = inputName;
                } else {
                    // Submit the explicit choices so the server can explain every inferred selection.
                    choice.removeAttribute('name');
                }
            }
        });
    };
})(Icinga);
