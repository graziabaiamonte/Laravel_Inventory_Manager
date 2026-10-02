import React, { useEffect, useState } from 'react';
import 'tinymce/tinymce';
import 'tinymce/plugins/link';
import 'tinymce/plugins/table';
import 'tinymce/skins/ui/oxide/skin.min.css';
import contentUiCss from 'tinymce/skins/content/default/content.min.css?inline';
// import 'tinymce/skins/content/default/content.css';
import 'tinymce/icons/default/icons';
import 'tinymce/themes/silver/theme';
import 'tinymce/models/dom/model';
import { Editor } from '@tinymce/tinymce-react';

export default function Wysiwyg({
    init,
    change,
    value,
    initialValue,
}: {
    init: any;
    change?: any;
    value?: any;
    initialValue?: string;
}) {
    return (
        <Editor
            onInit={init}
            onEditorChange={change}
            initialValue={initialValue || ''}
            value={value || ''}
            tinymceScriptSrc=''
            init={{
                height: 500,
                menubar: false,
                plugins: ['link', 'table'],
                // plugins: [
                //     'advlist', 'autolink', 'lists', 'link', 'charmap', 'preview',
                //     'anchor', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
                //     'insertdatetime', 'media', 'table', 'code', 'help', 'wordcount'
                // ],
                // toolbar: 'undo redo | blocks | ' +
                //     'bold italic underline | alignleft aligncenter ' +
                //     'alignright alignjustify | bullist numlist | table | ' +
                //     'removeformat | help',
                toolbar: 'undo redo | ' + 'bold italic underline | table | ' + 'removeformat | help',
                table_toolbar:
                    'tableprops tabledelete | tableinsertrowbefore tableinsertrowafter tabledeleterow tablerowheader | tableinsertcolbefore tableinsertcolafter tabledeletecol',
                // https://stackoverflow.com/questions/73699951/vite-inline-not-working-as-expected-when-loading-css-for-tinymce
                skin: false, // prevent tinymce script from looking for the skin css files since they're manually imported above
                content_css: false, // prevent tinymce script from looking for the content css files, use the imported styles below
                content_style: contentUiCss,
            }}
        />
    );
}
