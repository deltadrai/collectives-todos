<?php

/** @var array $defaults */
/** @var array $positions */
/** @var array $collectives */
/** @var string $form_action */
/** @var list<string> $errors */
?>
<div class="section" id="collectives-todos-settings">
    <?php if ($errors !== []): ?>
        <ul class="error">
            <?php foreach ($errors as $error): ?>
                <li><?php p($error); ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <form method="post" action="<?php p($form_action); ?>">
        <input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">

        <h3><?php p('Defaults'); ?></h3>
        <p class="settings-hint"><?php p('Applies to every collective without its own override.'); ?></p>

        <label for="default_todos_page_name"><?php p('Todos page name'); ?></label>
        <input type="text" id="default_todos_page_name" name="default_todos_page_name"
               value="<?php p($defaults['todos_page_name']); ?>">

        <label><?php p('Position in the page tree'); ?></label>
        <?php foreach ($positions as $value => $label): ?>
            <label class="radio">
                <input type="radio" name="default_tree_position" value="<?php p($value); ?>"
                    <?php if ($defaults['tree_position'] === $value): ?> checked<?php endif; ?>>
                <?php p($label); ?>
            </label>
        <?php endforeach; ?>

        <label for="default_max_checkboxes"><?php p('Maximum checkboxes per collective (0 = unlimited)'); ?></label>
        <input type="number" id="default_max_checkboxes" name="default_max_checkboxes" min="0"
               value="<?php p($defaults['max_checkboxes']); ?>">

        <h3><?php p('Per-collective overrides'); ?></h3>
        <p class="settings-hint"><?php p('Empty fields use the defaults.'); ?></p>

        <table class="grid">
            <thead>
            <tr>
                <th><?php p('Collective'); ?></th>
                <th><?php p('Todos page name'); ?></th>
                <th><?php p('Position in the page tree'); ?></th>
                <th><?php p('Maximum checkboxes'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($collectives as $collective): ?>
                <tr>
                    <td><?php p($collective['name']); ?></td>
                    <td>
                        <input type="text" name="override[<?php p($collective['id']); ?>][todos_page_name]"
                               value="<?php p($collective['todos_page_name']); ?>" placeholder="<?php p($defaults['todos_page_name']); ?>">
                    </td>
                    <td>
                        <select name="override[<?php p($collective['id']); ?>][tree_position]">
                            <option value=""><?php p('Use default'); ?></option>
                            <?php foreach ($positions as $value => $label): ?>
                                <option value="<?php p($value); ?>"
                                    <?php if ($collective['tree_position'] === $value): ?> selected<?php endif; ?>>
                                    <?php p($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <input type="number" name="override[<?php p($collective['id']); ?>][max_checkboxes]" min="0"
                               value="<?php p($collective['max_checkboxes']); ?>" placeholder="<?php p((string)$defaults['max_checkboxes']); ?>">
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <button type="submit" class="button primary"><?php p('Save'); ?></button>
    </form>
</div>
