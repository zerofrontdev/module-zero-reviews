# Changelog: zerofront/module-zero-reviews

## 1.3.6

- A review post larger than PHP's `post_max_size` gets a clear message and goes back to the product, instead of "Invalid Form Key".
- The Google product reviews feed is paged in a stable order, so no review moves between pages.
- A photo that can't be written (full disk, missing folder) fails the upload cleanly, without a half-written file.

Earlier releases (1.3.5 and before): see the Git tags.
