import { ApiProperty } from '@nestjs/swagger';
import { ServiceDto } from './service.dto';

export class ServicesPageDto {
  @ApiProperty({ type: [ServiceDto] })
  items!: ServiceDto[];

  @ApiProperty({ example: 1 })
  page!: number;

  @ApiProperty({ example: 10 })
  perPage!: number;

  @ApiProperty({ example: 0 })
  total!: number;
}

