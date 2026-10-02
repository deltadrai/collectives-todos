<?php

/** @var array $defaults */
/** @var array $positions */
/** @var array $collectives */
/** @var string $form_action */
/** @var list<string> $errors */
?>
<style>
    #collectives-todos-settings .field { margin-bottom: 12px; }
    #collectives-todos-settings .field > label { display: block; margin-bottom: 4px; }
    #collectives-todos-settings .radio-group { display: flex; flex-direction: column; gap: 4px; }
    #collectives-todos-settings .radio-group label { display: inline-flex; align-items: center; gap: 6px; }
    #collectives-todos-settings ul.error { color: var(--color-error, #d41010); }
    #collectives-todos-settings table.grid th, #collectives-todos-settings table.grid td { padding: 4px 8px; text-align: left; }
    #collectives-todos-settings .emoji-input { display: inline-flex; align-items: center; gap: 4px; }
    #collectives-todos-settings .emoji-input input { width: 5em; text-align: center; }
    #collectives-todos-settings .button.primary { margin-top: 12px; }
    #collectives-todos-emoji-picker { position: fixed; z-index: 1000; display: grid; grid-template-columns: repeat(8, 32px); gap: 2px; padding: 8px; max-width: 308px;
        background: var(--color-main-background, #fff); border: 1px solid var(--color-border, #dbdbdb); border-radius: var(--border-radius-large, 4px);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.25); }
    #collectives-todos-emoji-picker[hidden] { display: none; }
    #collectives-todos-emoji-picker button { width: 32px; height: 32px; padding: 0; font-size: 18px; line-height: 1; background: transparent; border: none; border-radius: 4px; cursor: pointer; }
    #collectives-todos-emoji-picker button:hover { background: var(--color-background-hover, #f0f0f0); }
    #collectives-todos-emoji-picker .emoji-none { grid-column: span 8; width: auto; height: 24px; font-size: 12px; }
</style>

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

        <div class="field">
            <label for="default_todos_page_name"><?php p('Todos page name'); ?></label>
            <input type="text" id="default_todos_page_name" name="default_todos_page_name"
                   value="<?php p($defaults['todos_page_name']); ?>">
        </div>

        <div class="field">
            <label for="default_todos_page_emoji"><?php p('Todos page emoji'); ?></label>
            <div class="emoji-input">
                <input type="text" id="default_todos_page_emoji" name="default_todos_page_emoji"
                       value="<?php p($defaults['todos_page_emoji']); ?>" placeholder="<?php p('None'); ?>">
                <button type="button" class="emoji-picker-trigger" data-target="default_todos_page_emoji">🙂</button>
            </div>
        </div>

        <div class="field">
            <label><?php p('Position in the page tree'); ?></label>
            <div class="radio-group">
                <?php foreach ($positions as $value => $label): ?>
                    <label>
                        <input type="radio" name="default_tree_position" value="<?php p($value); ?>"
                            <?php if ($defaults['tree_position'] === $value): ?> checked<?php endif; ?>>
                        <?php p($label); ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="field">
            <label for="default_max_checkboxes"><?php p('Maximum checkboxes per collective (0 = unlimited)'); ?></label>
            <input type="number" id="default_max_checkboxes" name="default_max_checkboxes" min="0"
                   value="<?php p($defaults['max_checkboxes']); ?>">
        </div>

        <div class="field">
            <label><?php p('Todos page management'); ?></label>
            <?php if ($defaults['enabled']): ?>
                <button type="submit" name="toggle_default_enabled" value="1"
                        class="button"><?php p('Disable'); ?></button>
            <?php else: ?>
                <button type="submit" name="toggle_default_enabled" value="1"
                        class="button primary"><?php p('Enable'); ?></button>
            <?php endif; ?>
            <p class="settings-hint"><?php p('Enabling resets every collective to enabled. Disabling a single collective is the manual choice in the table below.'); ?></p>
        </div>

        <h3><?php p('Per-collective overrides'); ?></h3>
        <p class="settings-hint"><?php p('Empty fields use the defaults. Disabled collectives get no Todos page.'); ?></p>

        <table class="grid">
            <thead>
            <tr>
                <th><?php p('Collective'); ?></th>
                <th><?php p('Todos page'); ?></th>
                <th><?php p('Todos page name'); ?></th>
                <th><?php p('Emoji'); ?></th>
                <th><?php p('Position in the page tree'); ?></th>
                <th><?php p('Maximum checkboxes'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($collectives as $collective): ?>
                <tr>
                    <td><?php p($collective['name']); ?></td>
                    <td>
                        <?php if ($collective['enabled']): ?>
                            <button type="submit" name="toggle_enabled" value="<?php p($collective['id']); ?>"
                                    class="button"><?php p('Disable'); ?></button>
                        <?php elseif ($defaults['enabled']): ?>
                            <button type="submit" name="toggle_enabled" value="<?php p($collective['id']); ?>"
                                    class="button primary"><?php p('Enable'); ?></button>
                        <?php else: ?>
                            <button type="button" class="button primary" disabled
                                   title="<?php p('The Todos page management is globally disabled'); ?>"><?php p('Enable'); ?></button>
                        <?php endif; ?>
                    </td>
                    <td>
                        <input type="text" name="override[<?php p($collective['id']); ?>][todos_page_name]"
                               value="<?php p($collective['todos_page_name']); ?>" placeholder="<?php p($defaults['todos_page_name']); ?>">
                    </td>
                    <td>
                        <div class="emoji-input">
                            <input type="text" id="collectives-todos-emoji-override-<?php p($collective['id']); ?>"
                                   name="override[<?php p($collective['id']); ?>][todos_page_emoji]"
                                   value="<?php p($collective['todos_page_emoji']); ?>"
                                   placeholder="<?php p($defaults['todos_page_emoji'] !== '' ? $defaults['todos_page_emoji'] : 'None'); ?>">
                            <button type="button" class="emoji-picker-trigger"
                                    data-target="collectives-todos-emoji-override-<?php p($collective['id']); ?>">🙂</button>
                        </div>
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

    <div id="collectives-todos-emoji-picker" hidden>
        <button type="button" data-emoji="" class="emoji-none" title="<?php p('No emoji'); ?>"><?php p('No emoji'); ?></button>
        <?php foreach ([
            '✅', '☑️', '✔️', '🚀', '🎯', '⭐', '🔥', '💡',
            '⚠️', '🔔', '🔖', '🏷️', '📌', '📍', '🧭', '🛠️',
            '📋', '🗒️', '📝', '📄', '📁', '📂', '🗂️', '🗃️',
            '📦', '✍️', '🖊️', '👍', '🤝', '💪', '🎉', '🏆',
            '📅', '🗓️', '⏰', '⏳', '🔒', '🔑', '🧩', '🔍',
            '📊', '📈', '💬', '💭', '🌱', '🌿', '🍀', '🌸',
            '🌍', '🐛', '🐝', '🦋', '❤️', '🧠', '👀', '🫶',
        ] as $emoji): ?>
            <button type="button" data-emoji="<?php p($emoji); ?>" title="<?php p($emoji); ?>"><?php p($emoji); ?></button>
        <?php endforeach; ?>
    </div>
</div>


