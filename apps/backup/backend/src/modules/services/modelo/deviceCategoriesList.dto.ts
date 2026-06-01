import { ApiProperty } from '@nestjs/swagger';
import { DeviceCategoryDto } from './deviceCategory.dto';

export class DeviceCategoriesListDto {
  @ApiProperty({ type: [DeviceCategoryDto] })
  items!: DeviceCategoryDto[];
}

