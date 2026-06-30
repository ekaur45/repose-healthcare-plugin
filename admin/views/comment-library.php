<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap repose-admin">
    <h1>Pre-Coded Comment Library</h1>
    <p>Manage standardised interpretation templates used when reviewing lab results.</p>

    <button class="button button-primary" id="btn-new-template">+ New Template</button>

    <div id="template-form" style="display:none; margin-top:16px; background:#fff; padding:16px; border:1px solid #ccd0d4;">
        <h3 id="template-form-title">New Template</h3>
        <input type="hidden" id="tmpl-id" value="0">
        <table class="form-table">
            <tr>
                <th><label for="tmpl-title">Title</label></th>
                <td><input type="text" id="tmpl-title" class="regular-text" required></td>
            </tr>
            <tr>
                <th><label for="tmpl-body">Body</label></th>
                <td><textarea id="tmpl-body" rows="4" style="width:100%"></textarea></td>
            </tr>
            <tr>
                <th><label for="tmpl-visibility">Visibility</label></th>
                <td>
                    <select id="tmpl-visibility">
                        <option value="patient">Patient-visible</option>
                        <option value="internal">Internal only</option>
                    </select>
                </td>
            </tr>
        </table>
        <button class="button button-primary" id="btn-save-template">Save Template</button>
        <button class="button" id="btn-cancel-template">Cancel</button>
        <span id="tmpl-feedback" style="margin-left:12px;color:green;"></span>
    </div>

    <hr>
    <table class="wp-list-table widefat fixed striped" id="templates-table">
        <thead>
            <tr>
                <th>Title</th>
                <th>Body</th>
                <th>Visibility</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( Repose_Comment_Library::get_all() as $tpl ) : ?>
            <tr id="tpl-row-<?php echo $tpl->id; ?>">
                <td><?php echo esc_html( $tpl->title ); ?></td>
                <td><?php echo esc_html( $tpl->body ); ?></td>
                <td><?php echo esc_html( ucfirst( $tpl->visibility ) ); ?></td>
                <td>
                    <button class="button btn-edit-template"
                        data-id="<?php echo $tpl->id; ?>"
                        data-title="<?php echo esc_attr( $tpl->title ); ?>"
                        data-body="<?php echo esc_attr( $tpl->body ); ?>"
                        data-vis="<?php echo esc_attr( $tpl->visibility ); ?>">Edit</button>
                    <button class="button btn-delete-template" data-id="<?php echo $tpl->id; ?>">Delete</button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
