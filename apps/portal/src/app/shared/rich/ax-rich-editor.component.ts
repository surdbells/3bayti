import {
  AfterViewInit,
  ChangeDetectionStrategy,
  ChangeDetectorRef,
  Component,
  ElementRef,
  EventEmitter,
  Input,
  OnDestroy,
  OnInit,
  Output,
  ViewChild,
  forwardRef,
  inject,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule, ControlValueAccessor, NG_VALUE_ACCESSOR } from '@angular/forms';
import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Underline from '@tiptap/extension-underline';
import Link from '@tiptap/extension-link';
import Placeholder from '@tiptap/extension-placeholder';

/**
 * Tiptap-based rich text editor. Text formatting only — no images, no
 * uploads. Outputs HTML. Replaces @kolkov/angular-editor usages in the app.
 *
 *   <app-ax-rich-editor
 *     [(ngModel)]="description"
 *     placeholder="Describe your product..."
 *     [minHeight]="'12rem'">
 *   </app-ax-rich-editor>
 *
 * Supported formatting (via toolbar):
 *   - Bold, italic, underline, strikethrough
 *   - Headings H1 / H2 / H3
 *   - Bulleted and numbered lists
 *   - Link (with URL prompt)
 *   - Blockquote
 *   - Inline code
 *   - Undo / redo
 *
 * Image paste and image drag-drop are explicitly stripped so users cannot
 * accidentally embed base64 blobs in post bodies. Use AxFileUploadComponent
 * separately for any image/document needs.
 */
@Component({
  selector: 'app-ax-rich-editor',
  standalone: true,
  imports: [CommonModule, FormsModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  providers: [
    {
      provide: NG_VALUE_ACCESSOR,
      useExisting: forwardRef(() => AxRichEditorComponent),
      multi: true,
    },
  ],
  template: `
    <div class="ax-editor" [class.ax-editor-focused]="isFocused">
      <div class="ax-editor-toolbar" role="toolbar" aria-label="Text formatting">
        <button type="button" class="ax-editor-btn"
          [class.ax-editor-btn-active]="isActive('bold')"
          [disabled]="!editor || disabled"
          (click)="run('toggleBold')"
          title="Bold (⌘B)" aria-label="Bold">
          <span class="material-symbols-outlined" aria-hidden="true">format_bold</span>
        </button>
        <button type="button" class="ax-editor-btn"
          [class.ax-editor-btn-active]="isActive('italic')"
          [disabled]="!editor || disabled"
          (click)="run('toggleItalic')"
          title="Italic (⌘I)" aria-label="Italic">
          <span class="material-symbols-outlined" aria-hidden="true">format_italic</span>
        </button>
        <button type="button" class="ax-editor-btn"
          [class.ax-editor-btn-active]="isActive('underline')"
          [disabled]="!editor || disabled"
          (click)="run('toggleUnderline')"
          title="Underline (⌘U)" aria-label="Underline">
          <span class="material-symbols-outlined" aria-hidden="true">format_underlined</span>
        </button>
        <button type="button" class="ax-editor-btn"
          [class.ax-editor-btn-active]="isActive('strike')"
          [disabled]="!editor || disabled"
          (click)="run('toggleStrike')"
          title="Strikethrough" aria-label="Strikethrough">
          <span class="material-symbols-outlined" aria-hidden="true">format_strikethrough</span>
        </button>

        <span class="ax-editor-divider" aria-hidden="true"></span>

        <button type="button" class="ax-editor-btn"
          [class.ax-editor-btn-active]="isActive('heading', { level: 1 })"
          [disabled]="!editor || disabled"
          (click)="toggleHeading(1)"
          title="Heading 1" aria-label="Heading 1">
          <span class="material-symbols-outlined" aria-hidden="true">format_h1</span>
        </button>
        <button type="button" class="ax-editor-btn"
          [class.ax-editor-btn-active]="isActive('heading', { level: 2 })"
          [disabled]="!editor || disabled"
          (click)="toggleHeading(2)"
          title="Heading 2" aria-label="Heading 2">
          <span class="material-symbols-outlined" aria-hidden="true">format_h2</span>
        </button>
        <button type="button" class="ax-editor-btn"
          [class.ax-editor-btn-active]="isActive('heading', { level: 3 })"
          [disabled]="!editor || disabled"
          (click)="toggleHeading(3)"
          title="Heading 3" aria-label="Heading 3">
          <span class="material-symbols-outlined" aria-hidden="true">format_h3</span>
        </button>

        <span class="ax-editor-divider" aria-hidden="true"></span>

        <button type="button" class="ax-editor-btn"
          [class.ax-editor-btn-active]="isActive('bulletList')"
          [disabled]="!editor || disabled"
          (click)="run('toggleBulletList')"
          title="Bulleted list" aria-label="Bulleted list">
          <span class="material-symbols-outlined" aria-hidden="true">format_list_bulleted</span>
        </button>
        <button type="button" class="ax-editor-btn"
          [class.ax-editor-btn-active]="isActive('orderedList')"
          [disabled]="!editor || disabled"
          (click)="run('toggleOrderedList')"
          title="Numbered list" aria-label="Numbered list">
          <span class="material-symbols-outlined" aria-hidden="true">format_list_numbered</span>
        </button>

        <span class="ax-editor-divider" aria-hidden="true"></span>

        <button type="button" class="ax-editor-btn"
          [class.ax-editor-btn-active]="isActive('link')"
          [disabled]="!editor || disabled"
          (click)="addLink()"
          title="Add link" aria-label="Add link">
          <span class="material-symbols-outlined" aria-hidden="true">link</span>
        </button>
        <button type="button" class="ax-editor-btn"
          [class.ax-editor-btn-active]="isActive('blockquote')"
          [disabled]="!editor || disabled"
          (click)="run('toggleBlockquote')"
          title="Blockquote" aria-label="Blockquote">
          <span class="material-symbols-outlined" aria-hidden="true">format_quote</span>
        </button>
        <button type="button" class="ax-editor-btn"
          [class.ax-editor-btn-active]="isActive('code')"
          [disabled]="!editor || disabled"
          (click)="run('toggleCode')"
          title="Inline code" aria-label="Inline code">
          <span class="material-symbols-outlined" aria-hidden="true">code</span>
        </button>

        <span class="ax-editor-divider" aria-hidden="true"></span>

        <button type="button" class="ax-editor-btn"
          [disabled]="!editor || disabled || !canUndo"
          (click)="run('undo')"
          title="Undo (⌘Z)" aria-label="Undo">
          <span class="material-symbols-outlined" aria-hidden="true">undo</span>
        </button>
        <button type="button" class="ax-editor-btn"
          [disabled]="!editor || disabled || !canRedo"
          (click)="run('redo')"
          title="Redo (⌘⇧Z)" aria-label="Redo">
          <span class="material-symbols-outlined" aria-hidden="true">redo</span>
        </button>
      </div>

      <div #editorEl
           class="ax-editor-body"
           [style.min-height]="minHeight"
           [style.max-height]="maxHeight"
      ></div>
    </div>
  `,
})
export class AxRichEditorComponent implements AfterViewInit, OnDestroy, ControlValueAccessor {
  @Input() placeholder = 'Start typing…';
  @Input() minHeight = '10rem';
  @Input() maxHeight = '30rem';
  @Input() disabled = false;

  @Output() valueChange = new EventEmitter<string>();

  @ViewChild('editorEl', { static: true }) private editorEl!: ElementRef<HTMLElement>;

  editor: Editor | null = null;
  isFocused = false;
  canUndo = false;
  canRedo = false;

  private pendingValue = '';
  private readonly cdr = inject(ChangeDetectorRef);

  private onChange: (value: string) => void = () => {};
  private onTouched: () => void = () => {};

  ngAfterViewInit(): void {
    this.editor = new Editor({
      element: this.editorEl.nativeElement,
      extensions: [
        StarterKit.configure({
          heading: { levels: [1, 2, 3] },
          // StarterKit already includes history, bold, italic, strike, etc.
        }),
        Underline,
        Link.configure({
          openOnClick: false,
          autolink: true,
          HTMLAttributes: { rel: 'noopener noreferrer', target: '_blank' },
        }),
        Placeholder.configure({
          placeholder: this.placeholder,
        }),
      ],
      content: this.pendingValue,
      editable: !this.disabled,
      editorProps: {
        attributes: {
          class: '',
          role: 'textbox',
          'aria-multiline': 'true',
        },
        // Strip pasted images — we explicitly don't support images.
        transformPastedHTML: (html: string) => html.replace(/<img[^>]*>/gi, ''),
        // Also strip dropped images.
        handleDrop: (_view, event) => {
          const dt = (event as DragEvent).dataTransfer;
          if (dt && dt.files.length && Array.from(dt.files).some(f => f.type.startsWith('image/'))) {
            event.preventDefault();
            return true;
          }
          return false;
        },
      },
      onUpdate: ({ editor }) => {
        const html = this.extractContent(editor);
        this.onChange(html);
        this.valueChange.emit(html);
        this.updateHistoryState(editor);
        this.cdr.markForCheck();
      },
      onFocus: () => {
        this.isFocused = true;
        this.cdr.markForCheck();
      },
      onBlur: () => {
        this.isFocused = false;
        this.onTouched();
        this.cdr.markForCheck();
      },
      onSelectionUpdate: ({ editor }) => {
        this.updateHistoryState(editor);
        // Re-render toolbar active states
        this.cdr.markForCheck();
      },
      onTransaction: ({ editor }) => {
        this.updateHistoryState(editor);
      },
    });
  }

  private updateHistoryState(editor: Editor): void {
    this.canUndo = editor.can().undo();
    this.canRedo = editor.can().redo();
  }

  /** Returns the editor HTML, or empty string if only placeholder content. */
  private extractContent(editor: Editor): string {
    if (editor.isEmpty) return '';
    return editor.getHTML();
  }

  // -------- Toolbar actions --------

  isActive(name: string, attrs?: Record<string, unknown>): boolean {
    return this.editor?.isActive(name, attrs as any) ?? false;
  }

  run(command: 'toggleBold' | 'toggleItalic' | 'toggleUnderline' | 'toggleStrike'
      | 'toggleBulletList' | 'toggleOrderedList' | 'toggleBlockquote'
      | 'toggleCode' | 'undo' | 'redo'): void {
    if (!this.editor) return;
    // Using the command chaining API
    const chain = this.editor.chain().focus();
    (chain as any)[command]().run();
  }

  toggleHeading(level: 1 | 2 | 3): void {
    this.editor?.chain().focus().toggleHeading({ level }).run();
  }

  addLink(): void {
    if (!this.editor) return;
    const previous = this.editor.getAttributes('link')['href'] as string | undefined;
    // Simple prompt — design-system demo only. In the app, wire up a proper
    // popover or modal. Keeping this minimal here avoids coupling to a
    // specific overlay system.
    const url = typeof window !== 'undefined'
      ? window.prompt('Enter URL', previous ?? 'https://')
      : null;
    if (url === null) return; // cancelled
    if (url === '') {
      this.editor.chain().focus().extendMarkRange('link').unsetLink().run();
      return;
    }
    // Basic validation — require a protocol-prefixed URL
    const normalised = /^https?:\/\//i.test(url) ? url : `https://${url}`;
    this.editor.chain().focus().extendMarkRange('link').setLink({ href: normalised }).run();
  }

  // -------- ControlValueAccessor --------

  writeValue(value: string | null): void {
    const safe = value ?? '';
    if (this.editor) {
      // Avoid writing if content is equivalent — prevents cursor jumps on
      // round-trip through ngModel.
      if (this.editor.getHTML() !== safe) {
        this.editor.commands.setContent(safe, false);
      }
    } else {
      this.pendingValue = safe;
    }
    this.cdr.markForCheck();
  }

  registerOnChange(fn: (v: string) => void): void { this.onChange = fn; }
  registerOnTouched(fn: () => void): void { this.onTouched = fn; }

  setDisabledState(disabled: boolean): void {
    this.disabled = disabled;
    this.editor?.setEditable(!disabled);
    this.cdr.markForCheck();
  }

  ngOnDestroy(): void {
    this.editor?.destroy();
    this.editor = null;
  }
}
