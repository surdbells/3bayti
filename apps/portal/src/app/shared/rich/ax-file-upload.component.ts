import {
  ChangeDetectionStrategy,
  ChangeDetectorRef,
  Component,
  ElementRef,
  EventEmitter,
  HostBinding,
  HostListener,
  Input,
  OnDestroy,
  Output,
  ViewChild,
  forwardRef,
  inject,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { ControlValueAccessor, NG_VALUE_ACCESSOR } from '@angular/forms';

/**
 * Metadata describing a file staged by the uploader. Not the native File
 * object — the component keeps both (the File on `file`, the preview URL on
 * `thumbUrl`) so consumers can inspect size, name, or mime without touching
 * the DOM object.
 */
export interface AxUploadFile {
  /** Stable local id (generated). */
  id: string;
  /** The underlying File from the input/drop. */
  file: File;
  /** File name (shortcut). */
  name: string;
  /** File size in bytes (shortcut). */
  size: number;
  /** Mime type (shortcut). */
  type: string;
  /** Object URL for images; undefined for non-image types. */
  thumbUrl?: string;
  /** Optional upload progress 0–100 (parent updates this). */
  progress?: number;
  /** Optional error message (parent sets on upload failure). */
  error?: string;
}

/**
 * Universal drag-and-drop file uploader. Accepts images, PDFs, docs, and
 * anything else via the `accept` input.
 *
 *   <app-ax-file-upload
 *     [accept]="'image/*,application/pdf'"
 *     [multiple]="true"
 *     [maxFiles]="10"
 *     [maxSizeMb]="5"
 *     [(ngModel)]="uploadedFiles"
 *     (filesChange)="handleUpload($event)">
 *   </app-ax-file-upload>
 *
 * The component is a ControlValueAccessor — the value is an array of
 * AxUploadFile. Parent components listen to filesChange to upload (component
 * does not POST to a server).
 *
 * After upload succeeds, parent can set `file.progress = 100` on each item,
 * or remove them from the array to clear the UI.
 */
@Component({
  selector: 'app-ax-file-upload',
  standalone: true,
  imports: [CommonModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  providers: [
    {
      provide: NG_VALUE_ACCESSOR,
      useExisting: forwardRef(() => AxFileUploadComponent),
      multi: true,
    },
  ],
  template: `
    <div class="ax-uploader">
      <label
        class="ax-uploader-dropzone"
        [class.ax-uploader-dragover]="isDragging"
        [class.ax-uploader-disabled]="disabled"
      >
        <input
          #fileInput
          type="file"
          [accept]="accept"
          [multiple]="multiple"
          [disabled]="disabled"
          hidden
          (change)="onInputChange($event)"
        />
        <span class="ax-uploader-icon">
          <span class="material-symbols-outlined" aria-hidden="true">{{ isDragging ? 'download' : 'upload_file' }}</span>
        </span>
        <p class="ax-uploader-title">
          <ng-container *ngIf="!isDragging">
            <strong>Click to browse</strong> or drag &amp; drop
          </ng-container>
          <ng-container *ngIf="isDragging">Drop files to upload</ng-container>
        </p>
        <p class="ax-uploader-hint">{{ hint }}</p>
      </label>

      <div *ngIf="errorMessage" class="ax-uploader-error" role="alert">
        <span class="material-symbols-outlined" aria-hidden="true">error</span>
        {{ errorMessage }}
      </div>

      <div *ngIf="files.length" class="ax-uploader-files">
        <div
          *ngFor="let f of files; trackBy: trackById"
          class="ax-uploader-file"
        >
          <div class="ax-uploader-file-thumb">
            <img *ngIf="f.thumbUrl" [src]="f.thumbUrl" [alt]="f.name" />
            <span *ngIf="!f.thumbUrl" class="material-symbols-outlined" aria-hidden="true">
              {{ iconFor(f.type) }}
            </span>
            <button
              type="button"
              class="ax-uploader-file-remove"
              [attr.aria-label]="'Remove ' + f.name"
              (click)="remove(f, $event)"
            >
              <span class="material-symbols-outlined" aria-hidden="true">close</span>
            </button>
          </div>
          <div *ngIf="f.progress != null && f.progress < 100" class="ax-uploader-file-progress">
            <div class="ax-uploader-file-progress-bar" [style.width.%]="f.progress"></div>
          </div>
          <div class="ax-uploader-file-meta">
            <span class="ax-uploader-file-name">{{ f.name }}</span>
            <span class="ax-uploader-file-size">{{ formatSize(f.size) }}</span>
            <span *ngIf="f.error" class="ax-uploader-file-size" style="color: var(--ax-color-text-danger);">
              {{ f.error }}
            </span>
          </div>
        </div>
      </div>
    </div>
  `,
})
export class AxFileUploadComponent implements ControlValueAccessor, OnDestroy {
  /** HTML accept attribute. Default accepts everything. */
  @Input() accept = '*/*';
  /** Allow multiple files. Default true. */
  @Input() multiple = true;
  /** Maximum total number of files. Default 10. 0 = unlimited. */
  @Input() maxFiles = 10;
  /** Max size per file in MB. Default 10. 0 = unlimited. */
  @Input() maxSizeMb = 10;
  /** Hint text under the dropzone. */
  @Input() hint = 'Images, PDFs, and documents up to 10 MB';
  @Input() disabled = false;

  /** Fired whenever the files array changes (add or remove). */
  @Output() filesChange = new EventEmitter<AxUploadFile[]>();

  files: AxUploadFile[] = [];
  isDragging = false;
  errorMessage: string | null = null;

  @ViewChild('fileInput') private fileInput?: ElementRef<HTMLInputElement>;
  private readonly cdr = inject(ChangeDetectorRef);
  private readonly hostRef = inject(ElementRef<HTMLElement>);

  private onChange: (value: AxUploadFile[]) => void = () => {};
  private onTouched: () => void = () => {};

  // -------- Host-level drag handlers (so the whole component is a drop target) --------

  @HostListener('dragover', ['$event'])
  onDragOver(e: DragEvent): void {
    if (this.disabled) return;
    e.preventDefault();
    e.stopPropagation();
    this.isDragging = true;
    this.cdr.markForCheck();
  }

  @HostListener('dragleave', ['$event'])
  onDragLeave(e: DragEvent): void {
    const host = this.hostRef.nativeElement;
    // Ignore leaves that move to a child element
    if (host.contains(e.relatedTarget as Node)) return;
    this.isDragging = false;
    this.cdr.markForCheck();
  }

  @HostListener('drop', ['$event'])
  onDrop(e: DragEvent): void {
    if (this.disabled) return;
    e.preventDefault();
    e.stopPropagation();
    this.isDragging = false;
    const list = e.dataTransfer?.files;
    if (list && list.length) this.ingest(list);
    this.cdr.markForCheck();
  }

  // -------- Input handlers --------

  onInputChange(e: Event): void {
    const input = e.target as HTMLInputElement;
    if (input.files && input.files.length) this.ingest(input.files);
    // Reset so the same file can be picked again after removal
    if (input) input.value = '';
  }

  // -------- Ingest & validate --------

  private ingest(list: FileList): void {
    this.errorMessage = null;
    const incoming = Array.from(list);
    const accepted: AxUploadFile[] = [];

    for (const file of incoming) {
      // Size check
      if (this.maxSizeMb > 0 && file.size > this.maxSizeMb * 1024 * 1024) {
        this.errorMessage = `"${file.name}" exceeds ${this.maxSizeMb} MB`;
        continue;
      }
      // Accept check (honour exact types & wildcards like image/*)
      if (!this.isAccepted(file)) {
        this.errorMessage = `"${file.name}" is not an accepted file type`;
        continue;
      }
      // Max files
      if (this.maxFiles > 0 && this.files.length + accepted.length >= this.maxFiles) {
        this.errorMessage = `You can only upload up to ${this.maxFiles} file(s)`;
        break;
      }

      const isImage = file.type.startsWith('image/');
      accepted.push({
        id: this.generateId(),
        file,
        name: file.name,
        size: file.size,
        type: file.type,
        thumbUrl: isImage ? URL.createObjectURL(file) : undefined,
      });
    }

    if (accepted.length) {
      this.files = this.multiple ? [...this.files, ...accepted] : [accepted[accepted.length - 1]];
      this.emit();
    }
  }

  private isAccepted(file: File): boolean {
    if (!this.accept || this.accept.trim() === '' || this.accept === '*/*') return true;
    const patterns = this.accept.split(',').map(s => s.trim().toLowerCase()).filter(Boolean);
    const mime = file.type.toLowerCase();
    const ext = '.' + (file.name.split('.').pop() ?? '').toLowerCase();
    return patterns.some(p => {
      if (p.endsWith('/*')) return mime.startsWith(p.slice(0, -1));
      if (p.startsWith('.')) return ext === p;
      return mime === p;
    });
  }

  // -------- Remove --------

  remove(f: AxUploadFile, event: MouseEvent): void {
    event.preventDefault();
    event.stopPropagation();
    if (f.thumbUrl) URL.revokeObjectURL(f.thumbUrl);
    this.files = this.files.filter(x => x.id !== f.id);
    this.errorMessage = null;
    this.emit();
  }

  clear(): void {
    for (const f of this.files) {
      if (f.thumbUrl) URL.revokeObjectURL(f.thumbUrl);
    }
    this.files = [];
    this.emit();
  }

  // -------- Helpers --------

  iconFor(mime: string): string {
    if (mime.startsWith('image/')) return 'image';
    if (mime === 'application/pdf') return 'picture_as_pdf';
    if (mime.includes('word') || mime.includes('document')) return 'description';
    if (mime.includes('sheet') || mime.includes('excel')) return 'table_chart';
    if (mime.includes('presentation') || mime.includes('powerpoint')) return 'slideshow';
    if (mime.startsWith('video/')) return 'movie';
    if (mime.startsWith('audio/')) return 'audiotrack';
    if (mime.includes('zip') || mime.includes('compressed')) return 'folder_zip';
    return 'draft';
  }

  formatSize(bytes: number): string {
    if (!bytes) return '0 B';
    const k = 1024;
    const units = ['B', 'KB', 'MB', 'GB'];
    const i = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(k)));
    return `${(bytes / Math.pow(k, i)).toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
  }

  trackById = (_: number, f: AxUploadFile): string => f.id;

  private generateId(): string {
    return 'f_' + Math.random().toString(36).slice(2, 10);
  }

  private emit(): void {
    this.onChange(this.files);
    this.filesChange.emit(this.files);
    this.onTouched();
    this.cdr.markForCheck();
  }

  // -------- ControlValueAccessor --------

  writeValue(value: AxUploadFile[] | null): void {
    this.files = Array.isArray(value) ? value : [];
    this.cdr.markForCheck();
  }

  registerOnChange(fn: (v: AxUploadFile[]) => void): void { this.onChange = fn; }
  registerOnTouched(fn: () => void): void { this.onTouched = fn; }

  setDisabledState(disabled: boolean): void {
    this.disabled = disabled;
    this.cdr.markForCheck();
  }

  ngOnDestroy(): void {
    for (const f of this.files) {
      if (f.thumbUrl) URL.revokeObjectURL(f.thumbUrl);
    }
  }
}
